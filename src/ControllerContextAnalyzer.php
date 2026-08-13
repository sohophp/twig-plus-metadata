<?php

namespace TwigPlus\Metadata;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Builds a small, persisted template-to-controller context index. PHP ASTs are
 * discarded after each file; unchanged files are restored from the hash cache.
 */
final class ControllerContextAnalyzer
{
    public function analyze($projectRoot, $cacheFile)
    {
        if (!is_dir(rtrim($projectRoot, '/\\').DIRECTORY_SEPARATOR.'src')) return array();
        $previous = $this->readCache($cacheFile);
        $files = $this->phpFiles(rtrim($projectRoot, '/\\').DIRECTORY_SEPARATOR.'src');
        $cached = array();
        $contexts = array();
        foreach ($files as $file) {
            $source = @file_get_contents($file);
            if (!is_string($source) || false === strpos($source, 'render')) continue;
            $relative = str_replace('\\', '/', substr($file, strlen(rtrim($projectRoot, '/\\')) + 1));
            $hash = sha1($source);
            if (isset($previous['files'][$relative]) && $previous['files'][$relative]['hash'] === $hash) {
                $entries = $previous['files'][$relative]['contexts'];
            } else {
                $entries = $this->analyzeFile($source, $relative);
            }
            $cached[$relative] = array('hash' => $hash, 'contexts' => $entries);
            foreach ($entries as $entry) $contexts[] = $entry;
        }
        $directory = dirname($cacheFile);
        if (!is_dir($directory)) @mkdir($directory, 0777, true);
        @file_put_contents($cacheFile, json_encode(array('version' => 1, 'files' => $cached), JSON_UNESCAPED_SLASHES));
        return $this->mergeContexts($contexts);
    }

    private function analyzeFile($source, $relative)
    {
        try {
            $factory = new ParserFactory();
            $parser = method_exists($factory, 'createForNewestSupportedVersion')
                ? $factory->createForNewestSupportedVersion()
                : $factory->create(ParserFactory::PREFER_PHP7);
            $nodes = $parser->parse($source);
            if (!$nodes) return array();
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $nodes = $traverser->traverse($nodes);
            $result = array();
            foreach ($nodes as $node) $this->analyzeTopLevel($node, $relative, $result);
            return $result;
        } catch (\Throwable $error) {
            return array();
        }
    }

    private function analyzeTopLevel(Node $node, $relative, array &$result)
    {
        if ($node instanceof Stmt\Namespace_) {
            foreach ($node->stmts as $child) $this->analyzeTopLevel($child, $relative, $result);
            return;
        }
        if (!$node instanceof Stmt\Class_) return;
        $class = isset($node->namespacedName) ? $node->namespacedName->toString() : (string) $node->name;
        foreach ($node->getMethods() as $method) {
            $types = array('this' => $class);
            foreach ($method->params as $parameter) {
                $name = is_string($parameter->var->name) ? $parameter->var->name : null;
                if ($name) $types[$name] = $this->nodeType($parameter->type);
            }
            $arrays = array();
            $source = $class.'::'.$method->name->toString();
            $this->walkStatements($method->stmts ?: array(), $types, $arrays, $source, $relative, $result);
        }
    }

    private function walkStatements(array $statements, array &$types, array &$arrays, $source, $relative, array &$result)
    {
        foreach ($statements as $statement) {
            $this->inspectExpressionTree($statement, $types, $arrays, $source, $relative, $result);
            foreach ($statement->getSubNodeNames() as $name) {
                $value = $statement->$name;
                if (is_array($value) && $value && $value[0] instanceof Stmt) $this->walkStatements($value, $types, $arrays, $source, $relative, $result);
                elseif ($value instanceof Stmt) $this->walkStatements(array($value), $types, $arrays, $source, $relative, $result);
            }
        }
    }

    private function inspectExpressionTree(Node $node, array &$types, array &$arrays, $source, $relative, array &$result)
    {
        $this->inspectNode($node, $types, $arrays, $source, $relative, $result);
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            if ($value instanceof Expr) $this->inspectExpressionTree($value, $types, $arrays, $source, $relative, $result);
            elseif (is_array($value)) foreach ($value as $child) if ($child instanceof Expr) $this->inspectExpressionTree($child, $types, $arrays, $source, $relative, $result);
        }
    }

    private function inspectNode(Node $node, array &$types, array &$arrays, $source, $relative, array &$result)
    {
        if ($node instanceof Expr\Assign) {
            if ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
                $name = $node->var->name;
                $types[$name] = $this->inferType($node->expr, $types);
                if ($node->expr instanceof Expr\Array_) $arrays[$name] = $this->arrayVariables($node->expr, $types);
            } elseif ($node->var instanceof Expr\ArrayDimFetch && $node->var->var instanceof Expr\Variable && is_string($node->var->var->name)) {
                $arrayName = $node->var->var->name;
                $key = $this->stringValue($node->var->dim);
                if (null !== $key) $arrays[$arrayName][$key] = $this->inferType($node->expr, $types);
            }
        }
        if (!$node instanceof Expr\MethodCall && !$node instanceof Expr\StaticCall) return;
        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
        if ('render' !== $method) return;
        $args = $node->args;
        $templateIndex = isset($args[0]) && null !== $this->stringValue($args[0]->value) ? 0 : (isset($args[1]) && null !== $this->stringValue($args[1]->value) ? 1 : null);
        if (null === $templateIndex) return;
        $template = $this->stringValue($args[$templateIndex]->value);
        $contextArg = isset($args[$templateIndex + 1]) ? $args[$templateIndex + 1]->value : null;
        $variables = $this->contextVariables($contextArg, $types, $arrays);
        $result[] = array('template' => $template, 'complete' => null !== $contextArg, 'variables' => $variables, 'sources' => array(array('controller' => $source, 'path' => $relative, 'line' => $node->getStartLine())));
    }

    private function contextVariables($expression, array $types, array $arrays)
    {
        if ($expression instanceof Expr\Array_) return $this->arrayVariables($expression, $types);
        if ($expression instanceof Expr\Variable && is_string($expression->name)) return isset($arrays[$expression->name]) ? $arrays[$expression->name] : array();
        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Name && 'compact' === strtolower($expression->name->toString())) {
            $result = array();
            foreach ($expression->args as $arg) {
                $name = $this->stringValue($arg->value);
                if (null !== $name) $result[$name] = isset($types[$name]) ? $types[$name] : 'mixed';
            }
            return $result;
        }
        return array();
    }

    private function arrayVariables(Expr\Array_ $array, array $types)
    {
        $result = array();
        foreach ($array->items as $item) {
            if (!$item) continue;
            $key = $this->stringValue($item->key);
            if (null !== $key) $result[$key] = $this->inferType($item->value, $types);
        }
        return $result;
    }

    private function inferType($expression, array $types)
    {
        if ($expression instanceof Expr\Variable && is_string($expression->name)) return isset($types[$expression->name]) ? $types[$expression->name] : 'mixed';
        if ($expression instanceof Expr\New_ && $expression->class instanceof Name) return $this->resolvedName($expression->class);
        if ($expression instanceof Scalar\String_) return 'string';
        if ($expression instanceof Scalar\LNumber) return 'int';
        if ($expression instanceof Scalar\DNumber) return 'float';
        if ($expression instanceof Expr\ConstFetch) {
            $name = strtolower($expression->name->toString());
            return in_array($name, array('true', 'false'), true) ? 'bool' : ('null' === $name ? 'null' : 'mixed');
        }
        if ($expression instanceof Expr\Array_) {
            $itemType = 'mixed';
            foreach ($expression->items as $item) if ($item) { $itemType = $this->inferType($item->value, $types); break; }
            return 'array<'.$itemType.'>';
        }
        if ($expression instanceof Expr\Ternary) return $this->inferType($expression->if ?: $expression->cond, $types);
        if ($expression instanceof Expr\BinaryOp\Coalesce) return $this->inferType($expression->left, $types);
        if ($expression instanceof Expr\MethodCall && $expression->name instanceof Node\Identifier) {
            $owner = $this->inferReceiverType($expression->var, $types);
            return $this->methodReturnType($owner, $expression->name->toString());
        }
        if ($expression instanceof Expr\PropertyFetch) return $this->propertyType($this->inferReceiverType($expression->var, $types), $expression->name instanceof Node\Identifier ? $expression->name->toString() : '');
        return 'mixed';
    }

    private function inferReceiverType($expression, array $types)
    {
        if ($expression instanceof Expr\Variable && is_string($expression->name)) return isset($types[$expression->name]) ? $types[$expression->name] : null;
        if ($expression instanceof Expr\PropertyFetch && $expression->name instanceof Node\Identifier) return $this->propertyType($this->inferReceiverType($expression->var, $types), $expression->name->toString());
        if ($expression instanceof Expr\MethodCall && $expression->name instanceof Node\Identifier) return $this->methodReturnType($this->inferReceiverType($expression->var, $types), $expression->name->toString());
        return null;
    }

    private function propertyType($class, $property)
    {
        try {
            if ($class && class_exists($class) && (new ReflectionClass($class))->hasProperty($property)) return $this->reflectionType((new ReflectionClass($class))->getProperty($property)->getType());
        } catch (\Throwable $error) {}
        return 'mixed';
    }

    private function methodReturnType($class, $method)
    {
        try {
            if ($class && (class_exists($class) || interface_exists($class)) && method_exists($class, $method)) return $this->reflectionType((new ReflectionMethod($class, $method))->getReturnType());
        } catch (\Throwable $error) {}
        return 'mixed';
    }

    private function reflectionType($type)
    {
        if ($type instanceof ReflectionNamedType) return $type->getName();
        if ($type && method_exists($type, 'getTypes')) foreach ($type->getTypes() as $candidate) if ('null' !== $candidate->getName()) return $candidate->getName();
        return 'mixed';
    }

    private function nodeType($type)
    {
        if ($type instanceof Name) return $this->resolvedName($type);
        if ($type instanceof Node\NullableType) return $this->nodeType($type->type);
        if ($type instanceof Node\UnionType) foreach ($type->types as $candidate) if ('null' !== (string) $candidate) return $this->nodeType($candidate);
        return $type ? (string) $type : 'mixed';
    }

    private function resolvedName(Name $name)
    {
        $resolved = $name->getAttribute('resolvedName');
        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }

    private function stringValue($node)
    {
        return $node instanceof Scalar\String_ ? $node->value : null;
    }

    private function mergeContexts(array $entries)
    {
        $merged = array();
        foreach ($entries as $entry) {
            $key = $entry['template'];
            if (!isset($merged[$key])) $merged[$key] = $entry;
            else {
                $merged[$key]['variables'] = array_merge($merged[$key]['variables'], $entry['variables']);
                $merged[$key]['sources'] = array_merge($merged[$key]['sources'], $entry['sources']);
                $merged[$key]['complete'] = $merged[$key]['complete'] && $entry['complete'];
            }
        }
        return array_values($merged);
    }

    private function phpFiles($directory)
    {
        if (!is_dir($directory)) return array();
        $result = array();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) if ($file->isFile() && 'php' === strtolower($file->getExtension())) $result[] = $file->getPathname();
        return $result;
    }

    private function readCache($file)
    {
        $value = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        return is_array($value) && isset($value['files']) ? $value : array('files' => array());
    }
}

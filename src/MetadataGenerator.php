<?php

namespace TwigPlus\Metadata;

use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use Twig\Environment;

final class MetadataGenerator
{
    private $maxTypes;
    private $recursiveNamespaces;

    public function __construct($maxTypes = 250, array $recursiveNamespaces = array())
    {
        $this->maxTypes = (int) $maxTypes;
        $this->recursiveNamespaces = $recursiveNamespaces;
    }

    public function generate(Environment $twig, $projectRoot)
    {
        $types = array();
        $globals = array();
        foreach ($twig->getGlobals() as $name => $value) {
            $type = $this->valueType($value);
            $globals[] = array('kind' => 'global', 'name' => (string) $name, 'type' => $type, 'detail' => 'Twig global · '.$type);
            if (is_object($value)) {
                $this->collectType(get_class($value), $types, $this->namespaceRoots($value, $projectRoot));
            }
        }

        $functions = $this->collectCallables($twig->getFunctions(), 'function', $types, $projectRoot);
        $filters = $this->collectCallables($twig->getFilters(), 'filter', $types, $projectRoot);
        $tests = $this->collectCallables($twig->getTests(), 'test', $types, $projectRoot);
        $version = defined('Twig\\Environment::VERSION') ? constant('Twig\\Environment::VERSION') : null;

        return array(
            'schemaVersion' => 3,
            'providerId' => 'twig-plus-metadata',
            'projectRoot' => realpath($projectRoot) ?: $projectRoot,
            'generatedAt' => (int) floor(microtime(true) * 1000),
            'environment' => array('twigVersion' => $version, 'catalogComplete' => true),
            'completions' => array_merge($functions, $filters, $tests),
            'symbols' => array('globals' => $globals, 'functions' => $functions, 'filters' => $filters, 'tests' => $tests, 'tags' => array()),
            'types' => $types,
            'contexts' => array(), 'references' => array(), 'templates' => array(), 'blocks' => array(), 'macros' => array(),
        );
    }

    private function collectCallables($callables, $kind, array &$types, $projectRoot)
    {
        $result = array();
        foreach ($callables as $callable) {
            $name = method_exists($callable, 'getName') ? $callable->getName() : null;
            if (!is_string($name) || '' === $name) continue;
            $reflection = $this->reflectCallable(method_exists($callable, 'getCallable') ? $callable->getCallable() : null);
            $returnType = $reflection ? $this->typeName($reflection->getReturnType(), $reflection) : null;
            $entry = array('kind' => $kind, 'name' => $name);
            if ($reflection) {
                $parameters = array();
                foreach ($reflection->getParameters() as $parameter) $parameters[] = '$'.$parameter->getName();
                $entry['signature'] = $name.'('.implode(', ', $parameters).')';
            }
            if ($returnType) {
                $entry['returnType'] = $returnType;
                $entry['detail'] = 'Project Twig '.$kind.' · '.$returnType;
                if (class_exists($returnType) || interface_exists($returnType)) $this->collectType($returnType, $types, $this->namespaceRoots($returnType, $projectRoot));
            }
            $result[] = $entry;
        }
        return $result;
    }

    private function reflectCallable($callable)
    {
        try {
            if ($callable instanceof \Closure) return new ReflectionFunction($callable);
            if (is_array($callable) && 2 === count($callable)) return new ReflectionMethod($callable[0], (string) $callable[1]);
            if (is_string($callable) && false !== strpos($callable, '::')) {
                $parts = explode('::', $callable, 2);
                return new ReflectionMethod($parts[0], $parts[1]);
            }
            if (is_string($callable) && function_exists($callable)) return new ReflectionFunction($callable);
            if (is_object($callable) && method_exists($callable, '__invoke')) return new ReflectionMethod($callable, '__invoke');
        } catch (\ReflectionException $error) {
        }
        return null;
    }

    private function collectType($class, array &$types, array $recursiveNamespaces)
    {
        if (isset($types[$class]) || count($types) >= $this->maxTypes || (!class_exists($class) && !interface_exists($class))) return;
        $reflection = new ReflectionClass($class);
        $members = array();
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) continue;
            $members[$property->getName()] = array('name' => $property->getName(), 'kind' => 'property', 'type' => $this->typeName($property->getType(), $property));
        }
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) continue;
            $methodName = $method->getName();
            $propertyName = $methodName;
            if (preg_match('/^(?:get|is|has)([A-Z].*)$/', $methodName, $match)) $propertyName = lcfirst($match[1]);
            $parameters = array();
            foreach ($method->getParameters() as $parameter) $parameters[] = '$'.$parameter->getName();
            $members[$propertyName] = array('name' => $propertyName, 'kind' => $propertyName === $methodName ? 'method' : 'property', 'type' => $this->typeName($method->getReturnType(), $method), 'signature' => $methodName.'('.implode(', ', $parameters).')');
        }
        $types[$class] = array('name' => $class, 'members' => array_values($members));
        foreach ($members as $member) {
            $memberType = isset($member['type']) ? $member['type'] : null;
            if ($memberType && $this->matchesNamespace($memberType, $recursiveNamespaces)) $this->collectType($memberType, $types, $recursiveNamespaces);
        }
    }

    private function typeName($type, $declaring)
    {
        if (!$type instanceof ReflectionType) return null;
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            if ('self' === $name && method_exists($declaring, 'getDeclaringClass')) return $declaring->getDeclaringClass()->getName();
            return $name;
        }
        if (method_exists($type, 'getTypes')) {
            foreach ($type->getTypes() as $candidate) {
                if ('null' !== $candidate->getName()) return $this->typeName($candidate, $declaring);
            }
        }
        return null;
    }

    private function valueType($value)
    {
        if (is_object($value)) return get_class($value);
        if (is_int($value)) return 'int';
        if (is_float($value)) return 'float';
        if (is_bool($value)) return 'bool';
        if (is_string($value)) return 'string';
        if (is_array($value)) return 'array';
        if (null === $value) return 'null';
        if (is_resource($value)) return 'resource';
        return 'mixed';
    }

    private function namespaceRoots($value, $projectRoot)
    {
        if ($this->recursiveNamespaces) return $this->recursiveNamespaces;
        $class = is_object($value) ? get_class($value) : $value;
        try {
            $file = (new ReflectionClass($class))->getFileName();
            if ($file && 0 === strpos($file, rtrim($projectRoot, '/\\').DIRECTORY_SEPARATOR)) {
                $position = strpos($class, '\\');
                return array(false === $position ? $class : substr($class, 0, $position + 1));
            }
        } catch (\ReflectionException $error) {
        }
        return array();
    }

    private function matchesNamespace($class, array $namespaces)
    {
        foreach ($namespaces as $namespace) if (0 === strpos($class, $namespace)) return true;
        return false;
    }
}

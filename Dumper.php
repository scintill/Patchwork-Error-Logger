<?php

namespace Patchwork\PHP;

class Dumper
{
    static

    $maxLength = 100,
    $maxDepth  = 10;

    protected static

    $token,
    $depth,
    $refCount,
    $arrayStack = array(),
    $objectStack = array(),
    $objectCasters = array('closure' => array(__CLASS__, 'castClosure'));


    static function dumpConst($a)
    {
        return self::dump($a, false);
    }

    static function dump(&$a, $ref = true)
    {
        self::$token = "\x9D" . md5(mt_rand(), true);
        self::$refCount = self::$depth = 0;
        ++self::$maxDepth; ++self::$maxLength;

        $d = self::refDump($a, $ref ? '1' : '') . "\n";

        foreach (self::$arrayStack as &$a) unset($a[self::$token]);

        --self::$maxDepth; --self::$maxLength;
        self::$arrayStack = self::$objectStack = array();

        return $d;
    }

    static function setObjectCaster($class, $callback)
    {
        self::$objectCasters[strtolower($class)] = $callback;
    }


    protected static function refDump(&$a, $ref = '1')
    {
        switch (true)
        {
        case true  === $a: return 'true';
        case false === $a: return 'false';
        case null  === $a: return 'null';
        case  INF  === $a: return  'INF';
        case -INF  === $a: return '-INF';
        case NAN   === $a: return 'NAN';

        case is_string($a):
            $ref = addcslashes($a, '"');

            if (false !== strpos($a, "\n"))
            {
                $ref = "\"\"\n" . $ref . "\"\"";
                $ref = str_replace("\n", "\n" . str_repeat('  ', self::$depth+1), $ref);
            }

            return '"' . $ref . '"' ;

        case is_array($a):
            if ($ref)
            {
                if (isset($a[self::$token])) return "[#{$a[self::$token]}]";
                $a[self::$token] = ++self::$refCount;
                $ref = '#' . $a[self::$token];
                self::$arrayStack[] =& $a;
                if (1 === count($a)) return $ref . '[]';
            }
            else if (!$a) return $ref . '[]';

            if (++self::$depth === self::$maxDepth) return $ref . '[...]';

            $i = $j = 0;
            $b = array();

            foreach ($a as $k => &$v)
            {
                if (++$j === self::$maxLength)
                {
                    $b[] = '...';
                    break;
                }
                else if (is_int($k) && 0 <= $k)
                {
                    $b[] = ($k !== $i ? $k . ' => ' : '') . self::refDump($v);
                    $i = $k + 1;
                }
                else
                {
                    if ('' === $ref)
                    {
                        if (isset($k[0]) && "\0" === $k[0]) $k = implode(':', explode("\0", substr($k, 1), 2));
                        else if (false !== strpos($k, ':')) $k = ':' . $k;
                    }
                    else if (self::$token === $k) continue;

                    $b[] = self::refDump($k) . ' => ' . self::refDump($v);
                }
            }

            $k = str_repeat('  ', self::$depth);
            --self::$depth;

            return $ref . "[\n{$k}" . implode(",\n{$k}", $b) . "\n" . substr($k, 2) . ']';

        case is_object($a):
            $h = spl_object_hash($a);
            $c = get_class($a);
            $ref = 'stdClass' !== $c ? $c : '';

            if (isset(self::$objectStack[$h]))
            {
                $ref .= '{#' . self::$objectStack[$h];
                $h = '';
            }
            else
            {
                self::$objectStack[$h] = ++self::$refCount;
                $ref .= '#' . self::$objectStack[$h] . '{';

                $h = null;
                $c = array($c => $c) + class_parents($a) + class_implements($a) + array('*' => '*');

                foreach ($c as $c)
                {
                    if (isset(self::$objectCasters[strtolower($c)]))
                    {
                        $c = self::$objectCasters[strtolower($c)];
                        $h = false !== $c ? call_user_func($c, $a) : false;
                        break;
                    }
                }

                if (null === $h) $h = (array) $a;
                if (false === $h) $h = '...';
                else $h = substr(self::refDump($h, ''), 1, -1);
            }

            return $ref . $h . '}';

        case is_resource($a):
            return ((string) $a) . ' (' . get_resource_type($a) . ')';

        // float and integer
        default: return (string) $a;
        }
    }

    static function castClosure($c)
    {
        $h = array();
        if (!class_exists('ReflectionFunction', false)) return $h;
        $c = new \ReflectionFunction($c);
        $c->returnsReference() && $h[] = '&';

        foreach ($c->getParameters() as $p)
        {
            $n = ($p->isPassedByReference() ? '&$' : '$') . $p->getName();

            if ($p->isDefaultValueAvailable()) $h[$n] = $p->getDefaultValue();
            else $h[] = $n;
        }

        $h['use'] = array();

        if (method_exists($c, 'getClosureThis')) $h['this'] = $c->getClosureThis();

        if (false === $h['file'] = $c->getFileName()) unset($h['file']);
        else $h['lines'] = $c->getStartLine() . '-' . $c->getEndLine();

        if (!$c = $c->getStaticVariables()) unset($h['use']);
        else foreach ($c as $p => &$c) $h['use']['$' . $p] =& $c;

        return $h;
    }
}
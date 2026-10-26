<?php

declare(strict_types=1);

namespace Workspace\Agents\Support;

/**
 * Path-pattern matching with the exact semantics of deployment-system's
 * ReleasePreparer::matches(): `**` crosses directories, `*` and `?` do not.
 * Release drafts are only useful if they classify paths the way the
 * release contract will, so this must not diverge from it.
 */
final class Glob
{
    /** @param list<string>|string $patterns */
    public static function matches(string $path, array|string $patterns): bool
    {
        $path = str_replace('\\', '/', $path);
        foreach ((array) $patterns as $pattern) {
            $quoted = preg_quote(str_replace('\\', '/', (string) $pattern), '#');
            $regex = str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]*', '[^/]'], $quoted);
            if (preg_match('#^'.$regex.'$#', $path) === 1) {
                return true;
            }
        }

        return false;
    }
}

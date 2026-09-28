<?php

namespace AISeeder\Analysis;

use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * Finds concrete Eloquent models under the given paths without assuming any folder layout.
 */
final class ModelFinder
{
    /**
     * @param  list<string>  $paths
     * @return list<class-string<Model>>
     */
    public function find(array $paths): array
    {
        $models = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $class = $this->classFromFile($file->getPathname());

                if ($class !== null && $this->isConcreteModel($class)) {
                    $models[] = $class;
                }
            }
        }

        $models = array_values(array_unique($models));
        sort($models);

        return $models;
    }

    /**
     * Reads the declared class name with the tokenizer so the file is only
     * autoloaded when it plausibly declares a class.
     */
    private function classFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);

        if ($contents === false || ! str_contains($contents, 'class ')) {
            return null;
        }

        $tokens = token_get_all($contents);
        $namespace = '';

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if (! is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $namespace = $tokens[$j][1];
                        break;
                    }
                }
            }

            if ($tokens[$i][0] === T_CLASS) {
                // Skip "Foo::class" and anonymous classes.
                $previous = $tokens[$i - 1] ?? null;
                if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                    continue;
                }

                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }

                    return is_array($tokens[$j]) && $tokens[$j][0] === T_STRING
                        ? ltrim($namespace.'\\'.$tokens[$j][1], '\\')
                        : null;
                }
            }
        }

        return null;
    }

    private function isConcreteModel(string $class): bool
    {
        try {
            if (! class_exists($class)) {
                return false;
            }

            $reflection = new ReflectionClass($class);

            return $reflection->isSubclassOf(Model::class) && $reflection->isInstantiable();
        } catch (Throwable) {
            return false;
        }
    }
}

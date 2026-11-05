<?php

declare(strict_types=1);

/** Architecture contract: declarations must explain their package purpose and public operations. */
it('keeps package classes and public source methods documented without an extraction namespace tree', function () {
    $root = dirname(__DIR__, 2);
    expect(is_dir($root.'/src/Managed'))->toBeFalse();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || $file->getFilename() === 'legacy-managed-namespaces.php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if (! preg_match('/namespace\s+([^;]+);/', $source, $namespace)) {
            continue;
        }
        $name = trim($namespace[1]).'\\'.$file->getBasename('.php');
        $reflection = new ReflectionClass($name);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse($name.' needs a class-purpose contract');
        expect(trim(preg_replace('~[/\*\s]+~', ' ', preg_split('/@\w/', $doc)[0])))->not->toBe('');
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isInternal() || ! is_string($method->getFileName()) || ! str_starts_with($method->getFileName(), $root.'/src/') || $method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }
            expect($method->getDocComment())->not->toBeFalse($name.'::'.$method->getName().' needs its operation contract');
        }
    }
});

it('keeps production PSR4 paths correct on case sensitive filesystems', function (): void {
    $root = dirname(__DIR__, 2);
    $manifest = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($manifest['autoload']['psr-4'] as $namespace => $paths) {
        foreach (is_array($paths) ? $paths : [$paths] as $path) {
            $current = $root;
            foreach (explode('/', trim($path, '/')) as $segment) {
                expect(in_array($segment, scandir($current), true))->toBeTrue($namespace.' must use the exact production directory case.');
                $current .= '/'.$segment;
            }
        }
    }
});

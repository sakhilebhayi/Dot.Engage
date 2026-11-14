<?php

declare(strict_types=1);

namespace Dot\Ui;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Illuminate\View\ComponentAttributeBag;

/**
 * Renders blade/switcher.blade.php outside a Laravel app (the static demo and the markup tests), from a ready view
 * model. Not used by hosts: they use <x-dot.switcher>.
 */
final class SwitcherView
{
    /**
     * @param  array<string, mixed>  $model  A PlatformSwitcher view model.
     * @param  array<string, mixed>  $props  platform, company, mode, companyName, logos.
     */
    public static function render(array $model, array $props = []): string
    {
        $files = new Filesystem();
        $cache = sys_get_temp_dir().'/dot-ui-blade-'.md5(__DIR__);
        $files->ensureDirectoryExists($cache);

        $container = new Container();
        $compiler = new BladeCompiler($files, $cache);
        $resolver = new EngineResolver();
        $resolver->register('blade', fn () => new CompilerEngine($compiler, $files));
        $factory = new Factory($resolver, new FileViewFinder($files, [dirname(__DIR__).'/blade']), new Dispatcher($container));

        return $factory->make('switcher', $props + ['model' => $model, 'attributes' => new ComponentAttributeBag()])->render();
    }
}

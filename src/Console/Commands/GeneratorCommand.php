<?php

namespace Statamic\Console\Commands;

use Exception;
use Facades\Statamic\Console\Processes\Composer;
use Illuminate\Console\GeneratorCommand as IlluminateGeneratorCommand;
use Statamic\Support\Str;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

abstract class GeneratorCommand extends IlluminateGeneratorCommand
{
    /**
     * Should path output be hidden?
     *
     * @var bool
     */
    public $hiddenPathOutput = false;

    /**
     * Execute the console command.
     *
     * @return bool|null
     */
    public function handle()
    {
        if (parent::handle() === false) {
            return false;
        }

        if ($this->hiddenPathOutput) {
            return;
        }

        $relativePath = $this->getRelativePath($this->getPath($this->qualifyClass($this->getNameInput())));

        $this->comment("Your {$this->typeLower} class awaits at: {$relativePath}");
    }

    /**
     * Get the stub file for the generator.
     *
     * @param string|null $stub
     * @return string
     */
    protected function getStub($stub = null)
    {
        $stub = $stub ?? $this->stub;

        return __DIR__.'/stubs/'.$stub;
    }

    /**
     * Get the desired class name from the input.
     *
     * @return string
     */
    protected function getNameInput()
    {
        return studly_case(parent::getNameInput());
    }

    /**
     * Get the default namespace for the class.
     *
     * @param string $rootNamespace
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return "$rootNamespace\\{$this->typePlural}";
    }

    /**
     * Get the root namespace for the class.
     *
     * @return string
     */
    protected function rootNamespace()
    {
        $default = $this->laravel->getNamespace();

        if ($addon = $this->argument('addon')) {
            $composerPath = $this->getAddonPath($addon).'/../composer.json';
        } else {
            return $default;
        }

        try {
            return collect(json_decode($this->files->get($composerPath), true)['autoload']['psr-4'])->flip()->get('src');
        } catch (Exception $exception) {
            return $default;
        }
    }

    /**
     * Get the destination class path.
     *
     * @param string $name
     * @return string
     */
    protected function getPath($name)
    {
        $name = Str::replaceFirst($this->rootNamespace(), '', $name);

        $basePath = $this->laravel['path'];

        if ($addon = $this->argument('addon')) {
            $basePath = $this->getAddonPath($addon);
        }

        $path = $basePath.'/'.str_replace('\\', '/', $name).'.php';

        return $path;
    }

    /**
     * Get addon path.
     *
     * @param string $addon
     * @return string
     */
    protected function getAddonPath($addon)
    {
        // If explicitly setting addon path from an external command like `make:addon`,
        // use explicit path and allow external command to handle path output.
        if (starts_with($addon, '/') && $this->files->exists($addon)) {
            $this->hiddenPathOutput = true;

            return $addon;
        }

        // Set fallback path.
        $fallbackPath = $this->laravel['path'];

        // Attempt to get addon path via composer.
        try {
            $path = Composer::installedPath($addon).'/src';
        } catch (Exception $exception) {
            $path = $fallbackPath;
        }

        // Ensure we don't use addon path if within composer vendor files.
        if ($pathIsInVendor = str_contains($path, base_path('vendor'))) {
            $path = $fallbackPath;
        }

        // Output helpful errors to clarify why we're falling back to app path.
        if (! isset($this->shownAddonPathError) && $pathIsInVendor) {
            $this->error('It not a good practice to modify vendor files, falling back to default path.');
            $this->shownAddonPathError = true;
        } elseif (! isset($this->shownAddonPathError) && $path == $fallbackPath) {
            $this->error('Could not find path for specified addon, falling back to default path.');
            $this->shownAddonPathError = true;
        }

        return $path;
    }

    /**
     * Get path relative to the project if possible, otherwise return absolute path.
     *
     * @param string $path
     * @return string
     */
    protected function getRelativePath($path)
    {
        return str_replace(base_path().'/', '', $path);
    }

    /**
     * Get appropriate JS path for generating vue files, etc.
     *
     * @param string $file
     * @return string
     */
    protected function getJsPath($file)
    {
        $basePath = $this->laravel['path'];

        // If addon argument was specified, attempt to get addon as base path.
        if ($addon = $this->argument('addon')) {
            $basePath = $this->getAddonPath($addon);
        }

        // If base path is user's app and resources/assets/js exists from an older laravel installation, use it.
        // It's possible the user started with a <=5.6 app and shifted to 5.7+, but kept old structure,
        // So we will check actual structure, rather than laravel version.
        if ($basePath == $this->laravel['path'] && $this->files->exists(resource_path('assets/js'))) {
            $basePath = resource_path('assets/js');
        }

        // If the base path is user's app and resource/assets/js doesn't exist, use standard laravel js path.
        elseif ($basePath == $this->laravel['path']) {
            $basePath = resource_path('js');
        }

        // Otherwise, specify addon base path.
        else {
            $basePath = $basePath.'/resources/js';
        }

        return $basePath.Str::ensureLeft($file, '/');
    }

    /**
     * Build the directory for the path if necessary.
     *
     * @param string $path
     * @return string
     */
    protected function makeDirectory($path)
    {
        $directory = $this->files->isDirectory($path) ? $path : dirname($path);

        $this->files->makeDirectory($directory, 0777, true, true);

        return $directory;
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array_merge(parent::getArguments(), [
            ['addon', InputArgument::OPTIONAL, 'The package name of an addon (ie. john/my-addon)'],
        ]);
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['force', null, InputOption::VALUE_NONE, "Create the {$this->typeLower} even if it already exists"],
        ];
    }

    /**
     * Get attribute with special `type` modifier handling.
     *
     * @param mixed $attribute
     */
    public function __get($attribute)
    {
        $words = explode('_', snake_case($attribute));

        // If trying to access `type` attribute, allow dynamic string manipulation like `typeLowerPlural`.
        if ($words[0] === 'type') {
            unset($words[0]);

            return Str::modifyMultiple($this->type, $words);
        }

        return $this->{$attribute};
    }
}

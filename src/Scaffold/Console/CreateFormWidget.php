<?php namespace Winter\Storm\Scaffold\Console;

use Winter\Storm\Scaffold\GeneratorCommand;

class CreateFormWidget extends GeneratorCommand
{
    /**
     * The default command name for lazy loading.
     *
     * @var string|null
     */
    protected static $defaultName = 'create:formwidget';

    /**
     * The name and signature of this command.
     *
     * @var string
     */
    protected $signature = 'create:formwidget
        {plugin : The name of the plugin. <info>(eg: Winter.Blog)</info>}
        {widget : The name of the form widget to generate. <info>(eg: PostList)</info>}
        {--force : Overwrite existing files with generated files.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates a new form widget.';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'FormWidget';

    /**
     * A mapping of stub to generated file.
     *
     * @var array
     */
    protected $stubs = [
        'formwidget/formwidget.stub'      => 'formwidgets/{{studly_name}}.php',
        'formwidget/partial.stub'         => 'formwidgets/{{lower_name}}/partials/_{{lower_name}}.htm',
        'formwidget/stylesheet.stub'      => 'formwidgets/{{lower_name}}/assets/css/{{lower_name}}.css',
        'formwidget/javascript.stub'      => 'formwidgets/{{lower_name}}/assets/js/{{lower_name}}.js',
    ];

    /**
     * Prepare variables for stubs.
     *
     * return @array
     */
    protected function prepareVars()
    {
        $pluginCode = $this->argument('plugin');

        $parts = explode('.', $pluginCode);
        $plugin = array_pop($parts);
        $author = array_pop($parts);

        $widget = $this->argument('widget');

        return [
            'name' => $widget,
            'author' => $author,
            'plugin' => $plugin
        ];
    }
}

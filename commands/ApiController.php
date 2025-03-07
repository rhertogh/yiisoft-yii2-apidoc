<?php
/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\apidoc\commands;

use yii\apidoc\components\BaseController;
use yii\apidoc\models\Context;
use yii\apidoc\renderers\ApiRenderer;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;
use yii\helpers\FileHelper;

/**
 * Generate class API documentation.
 *
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
class ApiController extends BaseController
{
    /**
     * @var string url to where the guide files are located
     */
    public $guide;
    /**
     * @var string prefix to prepend to all guide file names.
     */
    public $guidePrefix = 'guide-';
    /**
     * @var string Repository url (e.g. "https://github.com/yiisoft/yii2"). Optional, used for resolving relative links
     * within a repository (e.g. "[docs/guide/README.md](docs/guide/README.md)"). If you don't have such links you can
     * skip this. Otherwise, skipping this will cause generation of broken links because they will be not resolved and
     * left as is.
     */
    public $repoUrl;


    // TODO add force update option
    /**
     * Renders API documentation files
     * @param array $sourceDirs
     * @param string $targetDir
     * @return int status code.
     */
    public function actionIndex(array $sourceDirs, $targetDir)
    {
        $renderer = $this->findRenderer($this->template);
        $targetDir = $this->normalizeTargetDir($targetDir);
        if ($targetDir === false || $renderer === false) {
            return 1;
        }

        $renderer->apiUrl = './';
        $renderer->guidePrefix = $this->guidePrefix;

        if ($this->pageTitle !== null) {
            $renderer->pageTitle = $this->pageTitle;
        }

        // setup reference to guide
        if ($this->guide !== null) {
            $renderer->guideUrl = $guideUrl = $this->guide;
        } else {
            $guideUrl = './';
            $renderer->guideUrl = $targetDir;
            if (file_exists($renderer->generateGuideUrl('README.md'))) {
                $renderer->guideUrl = $guideUrl;
            } else {
                $renderer->guideUrl = null;
            }
        }

        $renderer->repoUrl = rtrim($this->repoUrl, '/');

        // search for files to process
        if (($files = $this->searchFiles($sourceDirs)) === false) {
            return 1;
        }

        // load context from cache
        $context = $this->loadContext($targetDir);
        $this->stdout('Checking for updated files... ');
        foreach ($context->files as $file => $sha) {
            if (!file_exists($file)) {
                $this->stdout('At least one file has been removed. Rebuilding the context...');
                $context = new Context();
                if (($files = $this->searchFiles($sourceDirs)) === false) {
                    return 1;
                }
                break;
            }
            if (sha1_file($file) === $sha) {
                unset($files[$file]);
            }
        }
        $this->stdout('done.' . PHP_EOL, Console::FG_GREEN);

        // process files
        $fileCount = count($files);
        $this->stdout($fileCount . ' file' . ($fileCount == 1 ? '' : 's') . ' to update.' . PHP_EOL);
        Console::startProgress(0, $fileCount, 'Processing files... ', false);
        $done = 0;
        foreach ($files as $file) {
            try {
                $context->addFile($file);
            } catch (\Exception $e) {
                $context->errors[] = "Unable to process \"$file\": " . $e->getMessage();
            }
            Console::updateProgress(++$done, $fileCount);
        }
        $context->processFiles();
        Console::endProgress(true);
        $this->stdout('done.' . PHP_EOL, Console::FG_GREEN);

        // save processed data to cache
        $this->storeContext($context, $targetDir);

        $this->updateContext($context);

        // render models
        $renderer->controller = $this;
        $renderer->render($context, $targetDir);

        if (!empty($context->errors)) {
            ArrayHelper::multisort($context->errors, 'file');
            file_put_contents($targetDir . '/errors.txt', print_r($context->errors, true));
            $this->stdout(count($context->errors) . " errors have been logged to $targetDir/errors.txt\n", Console::FG_RED, Console::BOLD);
        }

        if (!empty($context->warnings)) {
            ArrayHelper::multisort($context->warnings, 'file');
            file_put_contents($targetDir . '/warnings.txt', print_r($context->warnings, true));
            $this->stdout(count($context->warnings) . " warnings have been logged to $targetDir/warnings.txt\n", Console::FG_YELLOW, Console::BOLD);
        }

        return 0;
    }

    /**
     * @inheritdoc
     */
    protected function findFiles($path, $except = [])
    {
        if (empty($except)) {
            $except = ['vendor/', 'tests/'];
        }
        $path = FileHelper::normalizePath($path);
        $options = [
            'filter' => function ($path) {
                    if (is_file($path)) {
                        $file = basename($path);
                        if ($file[0] < 'A' || $file[0] > 'Z') {
                            return false;
                        }
                    }

                    return null;
                },
            'only' => ['*.php'],
            'except' => $except,
        ];

        return FileHelper::findFiles($path, $options);
    }

    /**
     * @inheritdoc
     * @return ApiRenderer|false
     */
    protected function findRenderer($template)
    {
        // find renderer by class name
        if (class_exists($template)) {
            return new $template();
        }

        $rendererClass = 'yii\\apidoc\\templates\\' . $template . '\\ApiRenderer';
        if (!class_exists($rendererClass)) {
            $this->stderr('Renderer not found.' . PHP_EOL);

            return false;
        }

        return new $rendererClass();
    }

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['guide', 'guidePrefix', 'repoUrl']);
    }
}

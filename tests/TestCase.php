<?php


namespace Printful\GettextCms\Tests;


use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * Delete this directory with all files in it
     *
     * @param $path
     */
    protected function deleteDirectory($path)
    {
        $path = rtrim($path, '/');

        $childDirName = pathinfo($path, PATHINFO_BASENAME);
        $parentDir = dirname($path);

        $adapter = new Local($parentDir);
        $filesystem = new Filesystem($adapter);
        $filesystem->deleteDir($childDirName);
    }
}
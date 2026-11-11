<?php
namespace App;

class Config
{
    public static function getRootPath(): string
    {
        $override = getenv('PDF_READER_ROOT');
        return ($override !== false && $override !== '') ? $override : dirname(__DIR__);
    }

    public static function getMyfilePath(): string
    {
        return self::getRootPath() . DIRECTORY_SEPARATOR . 'myfile';
    }

    /**
     * upload/ holds one folder per Job, with invoice and cn inside, for the files of the Job's work
     */
    public static function getUploadPath(): string
    {
        return self::getRootPath() . DIRECTORY_SEPARATOR . 'upload';
    }

    public static function getInvoicePath(): string
    {
        return self::getRootPath() . DIRECTORY_SEPARATOR . 'invoice';
    }

    public static function getCnPath(): string
    {
        return self::getRootPath() . DIRECTORY_SEPARATOR . 'cn';
    }
}

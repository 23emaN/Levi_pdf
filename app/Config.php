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

    public static function getInvoicePath(): string
    {
        return self::getRootPath() . DIRECTORY_SEPARATOR . 'invoice';
    }

    public static function getCnPath(): string
    {
        return self::getRootPath() . DIRECTORY_SEPARATOR . 'cn';
    }
}

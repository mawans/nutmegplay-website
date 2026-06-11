<?php
namespace App\Core;

class HtmxHelper
{
    /**
     * Check if the current request is an HTMX request
     */
    public static function isHtmxRequest(): bool
    {
        return isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] === 'true';
    }

    /**
     * Check if HTMX should push URL to browser history
     */
    public static function shouldPushUrl(): bool
    {
        return isset($_SERVER['HTTP_HX_PUSH_URL']) && $_SERVER['HTTP_HX_PUSH_URL'] === 'true';
    }

    /**
     * Get the HTMX target element
     */
    public static function getTarget(): ?string
    {
        return $_SERVER['HTTP_HX_TARGET'] ?? null;
    }

    /**
     * Get the HTMX trigger element
     */
    public static function getTrigger(): ?string
    {
        return $_SERVER['HTTP_HX_TRIGGER'] ?? null;
    }
}

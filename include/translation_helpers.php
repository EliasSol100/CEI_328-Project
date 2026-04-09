<?php

if (!function_exists('app_translate_escape_attr')) {
    function app_translate_escape_attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('app_translate_text_attrs')) {
    function app_translate_text_attrs(string $english, string $greek): string
    {
        return ' data-text-en="' . app_translate_escape_attr($english) . '"'
            . ' data-text-el="' . app_translate_escape_attr($greek) . '"';
    }
}

if (!function_exists('app_translate_html_attrs')) {
    function app_translate_html_attrs(string $englishHtml, string $greekHtml): string
    {
        return ' data-html-en="' . app_translate_escape_attr($englishHtml) . '"'
            . ' data-html-el="' . app_translate_escape_attr($greekHtml) . '"';
    }
}

if (!function_exists('app_translate_title_attrs')) {
    function app_translate_title_attrs(string $english, string $greek): string
    {
        return ' data-title-en="' . app_translate_escape_attr($english) . '"'
            . ' data-title-el="' . app_translate_escape_attr($greek) . '"';
    }
}

if (!function_exists('app_translate_aria_attrs')) {
    function app_translate_aria_attrs(string $english, string $greek): string
    {
        return ' data-aria-label-en="' . app_translate_escape_attr($english) . '"'
            . ' data-aria-label-el="' . app_translate_escape_attr($greek) . '"';
    }
}

if (!function_exists('app_translate_placeholder_attrs')) {
    function app_translate_placeholder_attrs(string $english, string $greek): string
    {
        return ' data-placeholder-en="' . app_translate_escape_attr($english) . '"'
            . ' data-placeholder-el="' . app_translate_escape_attr($greek) . '"';
    }
}

if (!function_exists('app_translate_page_title_attrs')) {
    function app_translate_page_title_attrs(string $english, string $greek): string
    {
        return ' data-page-title-en="' . app_translate_escape_attr($english) . '"'
            . ' data-page-title-el="' . app_translate_escape_attr($greek) . '"';
    }
}

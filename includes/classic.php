<?php

defined('ABSPATH') || exit;

class DispatcherClassic {

    static function render($email) {
        // https://developer.wordpress.org/reference/functions/get_language_attributes/
        // dir is added only for RTL
        $head = '<!doctype html>
<html ' . get_language_attributes() . ' xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<title></title>
<!--[if !mso]><!-->
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<!--<![endif]-->
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
    .alignleft { float: left; margin: 0.5em 1em 0.5em 0; }
    .alignright { float: right; margin: 0.5em 0 0.5em 1em; }
    </style>
  </head><body style="word-spacing:normal;">';

        $footer = '</body></html>';
        $snippet = '<div style="font-size: 10px; font-family: sans-serif; display: none;">' . wp_strip_all_tags($email->post_excerpt) . '</div>';

//        if (has_blocks($email)) {
//            $content = do_blocks($email->post_content);
//        } else {
        $content = wpautop($email->post_content);
//        }
        $content = do_shortcode($content);

        // Remove useless spaces, compress TD and TR, ...
        // TODO

        $buffer = $head . $snippet . '<div style="font-family: sans-serif">' .
                $content . '</div>' . $footer;

        // Nerd optimization, starting and ending spaces
        return preg_replace('/^[ \t]+|[ \t]+$/m', '', $buffer);
    }
}

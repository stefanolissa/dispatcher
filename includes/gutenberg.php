<?php

defined('ABSPATH') || exit;

class DispatcherGutenberg {

    static $debug = false;
    static $email_width = 700;
    static $colors = [];
    static $color_classes = [];
    static $bg_classes = [];
    static $classes = [
        'has-text-align-center' => 'text-align: center;',
        'has-text-align-left' => 'text-align: left;',
        'has-text-align-right' => 'text-align: right;',
        'has-xx-large-font-size' => 'font-size: 52px;',
        'has-x-large-font-size' => 'font-size: 40px;',
        'has-large-font-size' => 'font-size: 30px;',
        'has-medium-font-size' => 'font-size: 16px;',
        'has-small-font-size' => 'font-size: 14px;',

    ];

    static function init() {
//        add_action('setup_theme', function () {
//            global $_wp_theme_features;
//            //add_theme_support('editor-font-sizes', []);
//            //var_dump(get_theme_support( 'editor-font-sizes' ));
//            //var_dump($_wp_theme_features);
//            //var_dump(get_theme_support('editor-color-palette'));
//        });

        add_action('after_setup_theme', function () {
            global $_wp_theme_features;

//            add_theme_support('editor-font-sizes', [
//                [
//                    'name' => esc_attr__('Small', 'default'),
//                    'size' => 12,
//                    'slug' => 'smallxx'
//            ]]);
            //add_theme_support('default-font-sizes', false);
//            add_filter('wp_theme_json_data_default', function ($theme_json) {
//                return $theme_json;
//            });
//            add_filter('wp_theme_json_data_theme', function ($theme_json) {
//                //var_dump($theme_json);
//                $new_data = array(
//                    'version' => 3,
//                    'settings' => array(
//                        'typography' => array(
//                            //'fontSizes' => false,
//                            'fluid' => false, // Remove the use of the clamp() CSS function
//                        //'fontSizes' => [], // Disable all the standard font sizes
//                        //'defaultFontSizes' => false,
//                        //'customFontSizes' => true,
//                        ),
//                        'spacing' => [
//                            'spacingSizes' => [],
//                            "units" => ["px"]
//                        ]
//                    ),
//                );
//
//                return $theme_json->update_with($new_data);
//            });
        }, 999);

//        add_filter('wp_theme_json_data_blocks', function ($theme_json) {
//            return $theme_json;
//        });

        self::init_colors();

//        add_filter('wp_theme_json_data_theme', function ($theme_json) {
//            //var_dump($theme_json);
//            $new_data = array(
//                'version' => 3,
//                'settings' => array(
//                    'spacing' => [
//                        'spacingSizes' => [],
//                        "units" => ["px"]
//                    ]
//                ),
//            );
//
//            return $theme_json->update_with($new_data);
//        });
    }

    /**
     * Creates a map of the color slugs getting them from the theme settings.
     */
    static function init_colors() {

        if (wp_theme_has_theme_json()) {

            // Apparently returns the theme configuration merged with the old "theme support" data. If a theme.json
            // is missing an empty one is created... so that should be the only function to call... bah...
            $data = WP_Theme_JSON_Resolver::get_theme_data()->get_data();

            // The "palette" is a list of associative arrays containing colors definitions with
            // a slug (to create the CSS classes has-xxx-color and the hex color code.
            foreach ($data['settings']['color']['palette'] as $o) {
                // $o['color'] hex color code
                // $o['slug'] color slug
                // Map slugs and classes names to color for inline replacements
                self::$colors[$o['slug']] = $o['color'];

                // Can be merged
                self::$color_classes['has-' . $o['slug'] . '-color'] = $o['color'];
                self::$color_classes['has-' . $o['slug'] . '-background-color'] = $o['color'];

                self::$bg_classes['has-' . $o['slug'] . '-background-color'] = $o['color'];

                self::$classes['has-' . $o['slug'] . '-color'] = 'color: ' . $o['color'] . ';';
                self::$classes['has-' . $o['slug'] . '-background-color'] = 'background-color: ' . $o['color'] . ';';
            }
        } else {

            // Probably this is not a necessary branch
            $cs = get_theme_support('editor-color-palette');
            if ($cs) {
                foreach ($cs[0] as $c) {
                    self::$colors[$c['slug']] = $c['color'];
                    self::$color_classes['has-' . $c['slug'] . '-color'] = $c['color'];
                    self::$bg_classes['has-' . $c['slug'] . '-background-color'] = $c['color'];
                }
            }
        }
    }

    /**
     * Generates a CSS block for the known CSS classes.
     *
     * @return string
     */
    static function render_css() {
        $b = '';
        foreach (self::$classes as $k => $v) {
            $b .= '.' . $k . ' {' . $v . '}' . "\n";
        }
        return $b;
    }

    /**
     * Still not used.
     *
     * @param type $content
     * @return type
     */
    static function render_inline_css($content) {
        $style = file_get_contents(__DIR__ . '/inline.css');
        $style = str_replace(["\n", "\r"], '', $style);
        $rules = [];
        preg_match_all('|\s*\.(.*?)\{(.*?)\}\s*|s', $style, $rules);
        for ($i = 0; $i < count($rules[1]); $i++) {
            $class = trim($rules[1][$i]);
            $value = trim($rules[2][$i]);
            $value = preg_replace('|\s+|', ' ', $value);
            $content = str_replace(' inline-class="' . $class . '"', ' style="' . esc_attr($value) . '"', $content);
        }

        return $content;
    }

    /**
     * Returns the color as #RRGGBB from a color slug name.
     *
     * @param string $slug
     * @return string
     */
    static function get_color($slug, $default = 'transparent') {

        if (substr($slug, 0, 1) === '#') {
            return $slug;
        }

        return self::$colors[$slug] ?? $default;
    }

    /**
     * Extracts the style attribute content and remove the attribute.
     * I use the WP parser somewhere in this code, that function should be replaced.
     *
     * @param type $content
     * @return string
     */
    static function extract_style($content) {
        preg_match('|style="(.*?)"|s', $content, $matches);
        if (isset($matches[1])) {
            preg_replace('|style=".*?"|s', '', $content);
            return $matches[1] . ';';
        } else {
            return '';
        }
    }

    /**
     * I don't remember.
     *
     * @param type $style
     * @return type
     */
    static function remove_padding($style) {
        $style = preg_replace('|padding-[^;]+;?|', '', $style);
        return $style;
    }

    static function remove_margin($style) {
        $style = preg_replace('|margin-[^;]+;?|', '', $style);
        return $style;
    }

    /**
     * Returns the padding inline CSS style part getting the values from the
     * standard block attributes.
     *
     * @param array $data
     * @return string
     */
    static function get_padding_style(&$data) {
        $padding = $data['attrs']['style']['spacing']['padding'] ?? [];
        if (empty($padding)) {
            return 'padding: 0;';
        } else {
            return 'padding: ' . $padding['top'] . ' ' . $padding['right'] . ' ' . $padding['bottom'] . ' ' . $padding['left'] . ';';
        }
    }

    static function get_margin_style(&$data) {
        $margin = $data['attrs']['style']['spacing']['margin'] ?? [];
        if (empty($margin)) {
            return 'margin: 0;';
        } else {
            return 'margin: ' . $margin['top'] . ' ' . $margin['right'] . ' ' . $margin['bottom'] . ' ' . $margin['left'] . ';';
        }
    }

    static function get_border_radius_style(&$data, $def) {
        $border_radius = $data['attrs']['style']['border']['radius'] ?? [];
        if (empty($border_radius) && empty($def)) {
            return 'border-radius: 0;';
        } else {
            return 'border-radius: ' . ($border_radius['topLeft'] ?? $def['topLeft']) . ' '
                    . ($border_radius['topRight'] ?? $def['topRight']) . ' '
                    . ($border_radius['bottomRight'] ?? $def['bottomRight']) . ' '
                    . ($border_radius['bottomLeft'] ?? $def['bottomLeft']) . ';';
        }
    }

    static function get_wrapper_margin_style(&$data) {
        $margin = $data['attrs']['style']['spacing']['margin'] ?? [];
        if (empty($margin)) {
            return 'margin: 0 auto;';
        } else {
            return 'margin-top: ' . $margin['top'] . '; margin-bottom: ' . $margin['bottom'] . '; margin-right: auto; margin-left: auto;';
        }
    }

    /**
     * Returns the background inline CSS style part getting the values from the
     * standard block attributes.
     *
     * @param array $data
     * @return string
     */
    static function get_background_style(&$data) {
        $bg = self::get_color($data['attrs']['backgroundColor'] ?? '');
        if ($bg) {
            return "background-color: $bg;";
        }
        return '';
    }

    /**
     * Returns the common inline CSS style part getting the values from the
     * standard block attributes.
     *
     * @param array $data
     * @return string
     */
    static function get_common_style(&$data) {
//        "border": {
//                "width": "20px",
//                "color": "#F6CFF4",
//                "radius": {
//                    "topLeft": "20px",
//                    "topRight": "20px",
//                    "bottomLeft": "20px",
//                    "bottomRight": "20px"
//                }
//            }
        return self::get_background_style($data) . self::get_padding_style($data);
    }

    /**
     * Process all the classes found on a class attribute (as string) and return the
     * style attribute content to be used inline. The classes recognized are the ones referencing
     * colors, backgrounds, alignement and font size.
     *
     * @param string $class
     */
    static function class_to_style(&$class) {
        if (empty($class)) {
            return '';
        }

        $classes = explode(' ', $class);
        $style = '';
        foreach ($classes as $c) {
            if (isset(self::$classes[$c])) {
                $style .= self::$classes[$c] . ' ';
            }
        }
        return trim($style);
    }

    static function start_block(&$data) {
        $b = '';

        $outer_table_style = '';

        // When there is not a parent, we add the "row wrapper"
        if (empty($data['parentBlock'])) {
            if ($data['blockName'] === 'core/group') {
                $align = $data['attrs']['align'] ?? '';
                if ($align === 'full') {
                    $outer_table_style = self::get_background_style($data);
                }
            }

            $b .= "<!-- outer wrapper -->\n";
            $b .= '<table class="outer" border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%" style="width: 100% !important; ' . esc_attr($outer_table_style) . '">' . "\n";
            $b .= "<tr><td align='center' width='100%'>\n\n";

            $table_style = self::get_wrapper_margin_style($data);
            $table_style .= 'width: 100% !important; max-width: ' . (self::$email_width + 50) . 'px !important';
            $td_style = self::get_common_style($data);
            // Can it be considered general???
            $align = $data['attrs']['align'] ?? 'center';
            $td_style .= 'text-align: ' . esc_attr($align) . ';';

            $b .= "<!-- inner wrapper -->\n";
            $b .= '<table class="inner" cellpadding="0" cellspacing="0" border="0" role="presentation" width="100%" align="center" style="' . esc_attr($table_style) . '">' . "\n";
            $b .= '<tr><td width="100%" style="' . esc_attr($td_style) . '">' . "\n\n";
        } else {
            // Wrapper for inner blocks
            $table_style = self::get_margin_style($data);
            $td_style = self::get_common_style($data);
            // Can it be considered general???
            $align = $data['attrs']['align'] ?? 'center';
            $td_style .= 'text-align: ' . esc_attr($align) . ';';
            $b .= '<table cellpadding="0" cellspacing="0" border="0" role="presentation" width="100%" style="' . esc_attr($table_style) . '">' . "\n";
            $b .= "<tr>\n";
            $b .= "\t" . '<td style="' . esc_attr($td_style) . '">' . "\n";
        }

        return $b;
    }

    static function end_block(&$data) {
        $b = "\n";
        if (empty($data['parentBlock'])) {
            $b .= "</td></tr></table>\n";
            $b .= "<!-- /inner wrapper -->\n\n";
            $b .= "</td></tr></table>\n";
            $b .= "<!-- /outer wrapper -->\n\n";
        } else {
            $b .= "\t</td></tr></table>\n";
        }

        return $b;
    }

    /**
     * Wraps a content with a block container taking care of special layouts.
     *
     * @param string $content
     * @param array $data
     * @return string
     */
    static function wrap($content, $data) {

        $b = '';
        if (self::$debug) {
            $b .= "\n<!-- " . esc_html($data['blockName']) . " -->\n";
        }

        $parent_layout_type = $data['parentLayout']['type'] ?? 'default'; // flex, grid
        if ($parent_layout_type === 'flex') {
            $parent_orientation = $data['parentLayout']['orientation'] ?? 'horizontal';
            $parent_justify = $data['parentLayout']['justifyContent'] ?? 'left';
            // try to prpcess "minimumColumnWidth": "18rem" for grid
            $td_style = self::get_common_style($data);
            if ($parent_orientation === 'vertical') {
                $b .= "<tr>\n";
            }
            $b .= "\t" . '<td style="' . esc_attr($td_style) . '">' . "\n" . trim($content) . "\n" . '  </td>' . "\n";
            if ($parent_orientation === 'vertical') {
                $b .= "</tr>\n";
            }
        } else {

            $b .= self::start_block($data);
            $b .= '  ' . trim($content) . PHP_EOL;
            $b .= self::end_block($data);
        }

        if (self::$debug) {
            $b .= '<!-- /' . esc_html($data['blockName']) . " -->\n\n";
        }
        return $b;
    }

    static function render_paragraph($content, $data) {
        if (empty($data['attrs']['align'])) {
            $data['attrs']['align'] = 'left';
        }

        if (!empty($data['attrs']['backgroundColor'])) {
            if (empty($data['attrs']['style']['spacing']['padding'])) {
                $data['attrs'] = array_merge_recursive($data['attrs'], ['style' => ['spacing' => ['padding' => ['left' => '24px', 'top' => '24px', 'bottom' => '24px', 'right' => '24px']]]]);
            }
        }

        $tags = new WP_HTML_Tag_Processor($content);
        while ($tags->next_tag()) {
            $class = $tags->get_attribute('class');
            $style = self::class_to_style($class);

            $tag_style = $tags->get_attribute('style') ?? '';
            if (!empty($tag_style)) {
                // The padding is added to the container, Outlook does not consider it on paragraphs so we need to remove it...
                $style .= self::remove_padding($tag_style);
            }

            $tags->set_attribute('style', $style);

            break;
        }

        $content = $tags->get_updated_html();

        $b = self::render_data($data, false) . self::wrap($content, $data);

        return $b;
    }

    static function render_heading($content, $data) {

        $tags = new WP_HTML_Tag_Processor($content);

        $count = 0;

        // With a background color, add some padding
        if (!empty($data['attrs']['backgroundColor'])) {
            if (empty($data['attrs']['style']['spacing']['padding'])) {
                $data['attrs']['style']['spacing']['padding'] = ['left' => '24px', 'top' => '24px', 'bottom' => '24px', 'right' => '24px'];
            }
        }

        if (empty($data['attrs']['style']['spacing']['margin']['bottom'])) {
            $data['attrs']['style']['spacing']['margin']['bottom'] = '24px';
        }

        if (empty($data['attrs']['style']['typography'])) {
            $data['attrs']['style']['typography']['fontStyle'] = 'normal';
            $data['attrs']['style']['typography']['fontWeight'] = '400';
        }


        // Process even inner tags (usually mark, span to change color or font to inner words)
        while ($tags->next_tag()) {
            $count++;

            $class = $tags->get_attribute('class');
            if ($count == 1 && strpos($class, 'has-text-align') === false) {
                $class .= ' has-text-align-left';
            }
            $style = self::class_to_style($class);

            // Remove the default margin of Hx tags
            $tag_style = $tags->get_attribute('style') ?? '';
            if (!empty($tag_style)) {
                // The padding is added to the container, Outlook does not consider it
                $style .= self::remove_padding(self::remove_margin($tag_style)) . ';';
            }

            if ($count == 1) {
                $style .= 'margin: 0;';
            }

            $tags->set_attribute('style', $style);
        }

        $content = $tags->get_updated_html();

        return self::render_data($data, false) . self::wrap($content, $data);
    }

    static function render_spacer($content, $data) {
        $height = (int) ($data['attrs']['height'] ?? 0);
        $b = '<!-- spacer -->' . PHP_EOL;
        $b .= '<table cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin: 0">' . PHP_EOL . '<tr>' . PHP_EOL;
        $b .= '  <td aria-hidden="true" height="' . $height . '" style="font-size: 0; line-height: 0px;">&nbsp;</td>' . PHP_EOL;
        $b .= '</tr>' . PHP_EOL . '</table>' . PHP_EOL;
        $b .= '<!-- /spacer -->' . PHP_EOL . PHP_EOL;
        return self::render_data($data, false) . $b;
    }

    /**
     * Creates the wrapper for a responsive version for email clients when the columns block is used.
     *
     * @param string $content
     * @param array $data
     * @return string
     */
    static function render_columns($content, $data) {

        // Process the outer DIV to be used for columns container in an email
        // It is something like <div class="wp-block-columns is-layout-flex wp-container-core-columns-is-layout-1 wp-block-columns-is-layout-flex">
        $tags = new WP_HTML_Tag_Processor($content);

        while ($tags->next_tag()) {
            $tags->set_attribute('class', '');
            $tags->set_attribute('style', "text-align: center; font-size: 0;");
            break;
        }

        $content = $tags->get_updated_html();

        $b = self::wrap($content, $data);

        return $b;
    }

    static function render_column($content, $data) {
        $bg = self::get_color($data['attrs']['backgroundColor'] ?? '');
        // d by us on a previous step
        $width = (int) (self::$email_width * floatval($data['attrs']['width']) / 100) - 1;

        $tags = new WP_HTML_Tag_Processor($content);

        // Recycle the outer DIV to make the one we need.
        // TODO: process the padding, if needed
        while ($tags->next_tag()) {
            $tags->set_attribute('class', '');
            $tags->set_attribute('style', 'padding: 16px; font-size: 16px; background-color: ' . $bg);
            break;
        }
        $content = $tags->get_updated_html();

        // Start with PHP_EOL to have a good formatting being insde the columns block
        $b = PHP_EOL . PHP_EOL . '<!-- ' . esc_html($data['blockName']) . ' -->' . PHP_EOL;

        $b .= ' <div class="m-mw-100" style="width:100%; max-width:' . $width . 'px; display:inline-block; vertical-align: top;box-sizing: border-box;">' . PHP_EOL;

        // Above we used the outer div to create this one
        // $b .= '  <div style="font-size: 16px; background-color: ' . $bg . '">' . PHP_EOL;

        $b .= $content;

        //$b .= '  </div>' . PHP_EOL;
        $b .= ' </div>' . PHP_EOL;
        $b .= '<!-- /' . esc_html($data['blockName']) . ' -->' . PHP_EOL . PHP_EOL;

        // A columns does not require to be wrapped
        return $b;
    }

    /**
     * See even render_block_data().
     *
     * @param string $content
     * @param array $data
     * @return string
     */
    static function render_buttons($content, $data) {
        $b = '';
        $align = $data['attrs']['layout']['justifyContent'] ?? 'left';
        $orientation = $data['attrs']['orientation'] ?? '';
        if ($orientation === 'vertical') {
            $b .= '<table width="100%">';
        } else {
            $b .= '<table width="100%" style="width: 100% !important;" class="m-responsive" cellpadding="0" cellspacing="0" border="0" role="presentation"><tr><td align="' . $align . '">';
        }

        $b .= $content;

        if ($orientation === 'vertical') {
            $b .= '</table>';
        } else {
            $b .= '</td></tr></table>';
        }

        return self::render_data($data, false) . self::wrap($b, $data);
    }

    /**
     *
     *
     * <div class="wp-block-button"><a class="wp-block-button__link wp-element-button">First button</a></div>
     * @param type $content
     * @param type $data
     * @return string
     */
    static function render_button($content, $data) {

        if (empty($data['attrs']['style']['spacing']['padding'])) {
            $data['attrs'] = array_merge_recursive($data['attrs'], [
                'style' => [
                    'spacing' => [
                        'padding' => [
                            'top' => '10px',
                            'right' => '25px',
                            'bottom' => '10px',
                            'left' => '25px',
                        ]
                    ]
                ]
            ]);
        }

        $padding_style = self::get_padding_style($data);
        $border_radius_style = self::get_border_radius_style($data, [
            'topLeft' => '9999px',
            'topRight' => '9999px',
            'bottomLeft' => '9999px',
            'bottomRight' => '9999px',
        ]);

        $b = '';

        $bg = self::get_color($data['attrs']['backgroundColor'] ?? '#000000');
        $color = self::get_color($data['attrs']['textColor'] ?? '#FFFFFF');
        $font_family = 'inherit';
        $font_weight = $data['attrs']['typography']['fontWeight'] ?? 'normal';
        $font_size = '16';

        // Use the natural width until I can manage it in the right way
        //$width = $data['attrs']['width'] ?? '';
        //$width = $width ? $width . '%' : 'auto';
        $width = '';
        $align = 'center'; // Button label alignment


        $border_color = 'transparent';

        // Fix the missing href
        $tags = new WP_HTML_Tag_Processor($content);
        $a_style = '';
        $a_class = '';
        $href = '';
        $label = '';
        while ($tags->next_tag()) {
            // Font styles are applied to the external div
            if ($tags->get_tag() === 'DIV') {
                $style = $tags->get_attribute('style') ?? '';
            }
            if ($tags->get_tag() === 'A') {
                $a_style = $tags->get_attribute('style') . ';';
                $a_class = $tags->get_attribute('class') ?? '';
                $a_style .= self::class_to_style($a_class);
                $href = $tags->get_attribute('href') ?: '#';
                // $label = ... need to use the get_next_token(), too complex
            }
        }
        $content = $tags->get_updated_html();

        preg_match('|<a.*?href=[\'"](.*?)[\'"].*?>(.*?)</a>|', $content, $matches);
        //$url = $matches[1] ?? '#';
        $label = $matches[2] ?? 'Click';

        // Style of the inner A tag
        $a_style .= 'display:inline-block;'
                . 'color:' . $color . ';font-family:' . $font_family . ';'
                . 'xfont-size:' . $font_size . 'px;font-weight:' . $font_weight . ';'
                . 'line-height:120%;margin:0;text-decoration:none;text-transform:none;padding:10px 25px;mso-padding-alt:0px;'
                . $border_radius_style . $padding_style;

        $a_style .= $style; // Append the original style

        $table_style = 'border-collapse:separate !important;line-height:100%;';

        if (!empty($width)) {
            $a_style .= ' width:' . $width . ';';
            $table_style .= 'width:' . $width . ';';
        }

        $td_style = 'border-collapse:separate !important;cursor:auto;mso-padding-alt:10px 25px;background:' . $bg . ';';
        $td_style .= $border_radius_style;

        if (!empty($border_color)) {
            $td_style .= 'border: 1px solid ' . $border_color . ';';
        }

        if (self::$debug) {
            $b .= "\n<!-- core/button -->\n\n";
        }
        // We need to deal here with the parent orintantion (horizontal or vertical)
        $orientation = $data['parentLayout']['orientation'] ?? '';
        $justifyContent = $data['parentLayout']['justifyContent'] ?? 'left';
        if ($orientation === 'vertical') {
            $b .= '<tr><td align="' . $justifyContent . '">' . "\n";
        } else {
            $b .= '<div style="display: inline-block;">';
        }

        // The actual button email-safe
        $b .= '<table border="0" cellpadding="0" cellspacing="0" role="presentation" align="center" style="' . esc_attr($table_style) . '">' . PHP_EOL
                . "<tr>\n"
                . "\t" . '<td align="center" bgcolor="' . $bg . '" role="presentation" style="' . esc_attr($td_style) . '" valign="middle">'
                . "\n\t\t" . '<a href="' . esc_attr($href) . '" style="' . esc_attr($a_style) . '" target="_blank">' . $label . '</a>' . PHP_EOL
                . "\t</td>\n"
                . "</tr>\n"
                . "</table>\n";

        // A bit of space between the buttons
        if ($orientation === 'vertical') {
            $b .= '</td></tr>' . "\n";
        } else {
            $b .= '</div>';
        }

//        $b .= "</div>\n";
        if (self::$debug) {
            $b .= "\n<!-- /core/button -->\n\n";
        }

        return self::render_data($data, false) . $b;
    }

    static function render_latest_posts($content, $data) {
        //                [displayAuthor] => 1
//            [displayPostDate] => 1
//            [order] => asc
//            [orderBy] => title
//            [displayFeaturedImage] => 1
//            [featuredImageAlign] => center
//            [featuredImageSizeSlug] => medium
//            [featuredImageSizeWidth] => 225
//            [featuredImageSizeHeight] => 225

        $posts_to_show = $data['attrs']['postsToShow'] ?? 5;
        $size = $data['attrs']['featuredImageSizeSlug'] ?? 'thumbnail';
        $align = '';
        if (!empty($data['attrs']['displayFeaturedImage'])) {
            $align = $data['attrs']['featuredImageAlign'] ?? 'center';
        }
        $show_author = $data['attrs']['displayAuthor'] ?? false;
        $show_date = $data['attrs']['displayPostDate'] ?? false;
        // Full post content not managed
        $show_content = $data['attrs']['displayPostContent'] ?? false;
        $color = self::get_color($data['attrs']['textColor'] ?? '', '#000');
        $posts = get_posts(['numberposts' => $posts_to_show]);
        $content = '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">' . "\n";
        add_filter('wp_calculate_image_srcset_meta', '__return_null');
        foreach ($posts as $post) {
            if (self::$debug) {
                $content .= "<!-- post #" . $post->ID . " -->\n";
            }
            $url = get_the_permalink($post);

            if ($align === 'center') {
                $content .= "<tr>\n<td align=\"center\" style=\"padding-bottom: 16px\">\n";
                $content .= get_the_post_thumbnail($post, $size, ['style' => 'max-width: 100%; height: auto; display: inline-block;']);
                $content .= "</td>\n</tr>\n";
            }

            $content .= "<tr>\n";
            if ($align === 'left') {
                $content .= "<td>\n" . get_the_post_thumbnail($post, $size, ['style' => 'max-width: 100%; display: inline-block;']) . "\n</td>\n";
            }
            $content .= "<td>\n<h2 class=\"post-title\" inline-class=\"post-title\">" . '<a href="' . esc_attr($url) . '" style="text-decoration: none; color: ' . $color . ';">' . get_the_title($post) . "</a></h2>\n";
            if ($show_author) {
                $author_object = get_user_by('id', $post->post_author);
                if ($author_object) {
                    $author = apply_filters('the_author', $author_object->display_name);
                }
                $content .= '<p class="post-author" inline-class="post-author"><em>' . $author . '</em></p>' . "\n";
            }
            if ($show_date) {
                $content .= '<p class="post-date" inline-class="post-author"><em>' . get_the_date(false, $post) . '</em></p>' . "\n";
            }

            if ($show_content) {
                $content .= '<p class="post-excerpt" inline-class="post-excerpt"><a href="' . esc_attr($url) . '" style="text-decoration: none; color: ' . $color . ';">' . get_the_excerpt($post) . '</a></p>' . "\n";
            }

            $content .= "</td>\n";

            if ($align === 'right') {
                $content .= "<td>\n" . get_the_post_thumbnail($post, $size, ['style' => 'max-width: 100%; display: inline-block;']) . '</td>';
            }
            $content .= "</tr>\n";
            $content .= '<tr><td style="height: 32px">&nbsp;</td></tr>' . "\n\n";
        }
        remove_filter('wp_calculate_image_srcset_meta', '__return_null');

        $content .= "</table>\n";

        return self::render_data($data, false) . self::wrap($content, $data);
    }

    static function render_group($content, $data) {
        $tags = new WP_HTML_Tag_Processor($content);
        $style = '';
        while ($tags->next_tag()) {

            $class = $tags->get_attribute('class');
            $style = self::class_to_style($class);

            $tag_style = $tags->get_attribute('style') ?? '';
            if (!empty($tag_style)) {
                // The padding is added to the container, Outlook does not consider it
                $style .= self::remove_padding($tag_style);
            }

            break;
        }

        $b = '';

        //$b = self::render_data($data);
        $align = $data['attrs']['align'] ?? ''; // full is fill width
        // constrained is a normal group (?), flex is a row, flex+vertical is a stack
        $layout_type = $data['attrs']['layout']['type'] ?? 'constrained'; // flex
        $orientation = $data['attrs']['layout']['orientation'] ?? 'horizontal';

        $justify = $data['attrs']['layout']['justifyContent'] ?? 'left'; // space-between
        // This is a row. Elements inside that group check for the parent and if it's a row, they wrap themself
        // with a TD knwing they'll be cells of a table. It's not clear to me if it would be possible to
        // separate the content in cells directly here (keep in mind the groups can be nested!).
        if ($layout_type === 'flex') {
            // I need to remove the external DIV since it's required a table here.
            preg_match('|<div[^>]*>(.*)<\\/div>|s', $content, $matches);

            $b .= '<!-- group[flex] -->' . PHP_EOL;
            $b .= '<table align="' . esc_attr($justify) . '" cellpadding="0" cellspacing="16" border="0" role="presentation" style="' . esc_attr($style) . '">' . PHP_EOL;
            if ($orientation === 'horizontal') {
                $b .= '<tr>' . PHP_EOL;
                $b .= '  ' . $matches[1] . PHP_EOL;
                $b .= '</tr>' . PHP_EOL;
            } else {
                $b .= '  ' . $matches[1] . PHP_EOL;
            }
            $b .= '</table>' . PHP_EOL;
            $b .= '<!-- /group[flex] -->' . PHP_EOL . PHP_EOL;
            return self::wrap($b, $data);
        }

        $b .= self::wrap($content, $data);
        //$b .= self::end_block($data);

        return $b;
    }

    static function render_gallery($content, $data) {
        // usare $attr = apply_filters( 'wp_get_attachment_image_attributes', $attr, $attachment, $size );
        // per forzare le dimensioni a metà
//        add_filter('wp_get_attachment_image_attributes', function ($attr, $attachment, $size) {
//            var_dump($attr);
//            $attr['width'] = 280;
//            return $attr;
//        }, 10, 3);

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        add_image_size('emails-square-300', 300, 300, true);
        add_image_size('emails-square-600', 600, 600, true);
        add_image_size('emails-1200', 1200, 0);
        $b = '';
        //$size = 'medium';
        $columns = $data['attrs']['columns'] ?? 3;
        $image_width = floor(self::$email_width / $columns) - 16;
        $chunks = array_chunk($data['innerBlocks'], $columns);
        $size = [$image_width, $image_width, true];
        $b = '<!-- gallery -->' . PHP_EOL;
        $b .= '<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">' . "\n";
        foreach ($chunks as $chunk) {
            $b .= ' <tr>' . PHP_EOL;
            foreach ($chunk as $block) {
                $id = $block['attrs']['id'];
                wp_update_image_subsizes($id);
                $b .= '  <td align="center" valign="middle">';
                $image = wp_get_attachment_image_src($id, 'emails-square-300');
                // This does not works since it forces the width anmd height, we neeed to set it to the right value
                // to fit the column width for outlook - it does not respect the max-width).
                //$b .= wp_get_attachment_image($id, 'email', false, ['style' => 'max-width: 100%; height: auto;']);
                $b .= '<img src="' . esc_attr($image[0]) . '" width="' . ((int) $image_width) . '" style="max-width: 100%; height: auto; display: block">';
                $b .= '<br>';
                $b .= '</td>' . PHP_EOL;
            }
            $b .= ' </tr>' . PHP_EOL;
        }
        $b .= '</table>' . PHP_EOL;
        $b .= '<!-- /gallery -->' . PHP_EOL . PHP_EOL;
        return $b;
    }

    static function render_image($content, $data) {
        $tags = new WP_HTML_Tag_Processor($content);
        $tags->next_tag(); // figure
        if ($tags->get_tag() === 'FIGURE') {
            $tags->next_tag();
        }
        $alt = $tags->get_attribute('alt');
        $width = $data['attrs']['width'] ?? self::$email_width;
        $width = str_replace('px', '', $width);
        add_filter('wp_calculate_image_srcset_meta', '__return_null');
        $b = wp_get_attachment_image($data['attrs']['id'], [$width, 0], false, ['alt' => $alt, 'style' => 'border: 0; display: block; font-size: 12px; max-width: 100%; height: auto;']);
        remove_filter('wp_calculate_image_srcset_meta', '__return_null');
        return self::render_data($data, false) . self::wrap($b, $data);
    }

    static function render_media_text($content, $data) {

        $mediaPosition = $data['attrs']['mediaPosition'] ?? 'left';
        $content = preg_replace('|<figure.*?</figure>|i', '', $content);

        $tags = new WP_HTML_Tag_Processor($content);
        $tags->next_tag();
        $tags->set_attribute('class', '');
        $content = $tags->get_updated_html();
        $alt = 'Photo';
        $width = 600;
        $b = '';

        $b .= '<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">' . "\n";
        $b .= "<tr>\n";
        if ($mediaPosition === 'left') {

            $b .= "<td width='50%' align='left'>\n";

            $b .= wp_get_attachment_image($data['attrs']['mediaId'], [$width, 0], false, ['alt' => $alt, 'style' => 'border: 0; display: block; font-size: 12px; max-width: 100%; width: 100%; height: auto;']);

            $b .= "</td>\n";
        }

        $b .= "<td width='50%' style='padding: 16px'>\n";
        $b .= $content;
        $b .= "</td>\n";

        if ($mediaPosition === 'right') {

            $b .= "<td width='50%' align='right'>\n";

            $b .= wp_get_attachment_image($data['attrs']['mediaId'], [$width, 0], false, ['alt' => $alt, 'style' => 'border: 0; display: block; font-size: 12px; max-width: 100%; height: auto;']);

            $b .= "</td>\n";
        }

        $b .= "</tr>\n</table>\n";

        return self::render_data($data, false) . self::wrap($b, $data);
    }

    static function render_data($data, $esc_html = true) {
        if (!self::$debug)
            return '';
        //return '';
        //return '<pre style="overflow: auto; max-width: 500px; max-height: 300px; padding: 1rem; border: 1px solid #ddd; margin: 1rem; background-color: #eee">' . esc_html(json_encode($data, JSON_PRETTY_PRINT)) . '</pre>' . PHP_EOL . PHP_EOL;
        if ($esc_html) {
            return PHP_EOL . '<!-- ' . esc_html(json_encode($data, JSON_PRETTY_PRINT)) . ' -->' . PHP_EOL . PHP_EOL;
        } else {
            return PHP_EOL . '<!-- ' . json_encode($data, JSON_PRETTY_PRINT) . ' -->' . PHP_EOL . PHP_EOL;
        }
    }

    static function render_block($content, $data) {

        //error_log($data['blockName']);

        switch ($data['blockName']) {
            case 'core/paragraph':
                return self::render_paragraph($content, $data);

            case 'core/heading':
                return self::render_heading($content, $data);

//            case 'core/cover':
//                return '';

            case 'core/spacer':
                return self::render_spacer($content, $data);

            case 'core/columns':
                return self::render_columns($content, $data);

            case 'core/column':
                return self::render_column($content, $data);

            case 'core/latest-posts':
                return self::render_latest_posts($content, $data);

            case 'core/buttons':
                return self::render_buttons($content, $data);

            case 'core/button':
                return self::render_button($content, $data);

            case 'core/gallery':
                // The gallery has core/image inner blocks, but the render for those blocks is not
                // called, it seems the core/gallery does all the work
                return self::render_data($data) . self::render_gallery($content, $data);

            case 'core/group':
                // The gallery has core/image inner blocks, but the render for those blocks is not
                // called, it seems the core/gallery does all the work
                return self::render_data($data, false) . self::render_group($content, $data);

            case 'core/image':
                return self::render_image($content, $data);

            case 'core/media-text':
                return self::render_media_text($content, $data);
        }

        return $content;
    }

    /**
     * Filtro sui blocchi che permette di apportare modifiche MA da esperimenti la
     * modifica a innerContent e innerHTML non fa nulla di interessante.
     * E' possibile però impostare gli attributi che vengono conservati.
     *
     * @param array $block Rappresentazione del blocco, ma non l'oggetto (boh...)
     * @param array $source
     * @param WP_Block $parent
     * @return array
     */
    static function render_block_data($block, $source, $parent) {

        // So the rendering can be changed if a block a first level block or not
        // (needed to add the Outlook wrapper, for example)
        $block['parentBlock'] = $parent->name ?? '';

        switch ($block['blockName']) {
            case 'core/column':
                // Calcola la larghezza che poi serve per creare il corretto HTML
                if (empty($block['attrs']['width'])) {
                    $block['attrs']['width'] = 100 / count($parent->inner_blocks) . '%';
                }
                //print_r($source);
                break;

            case 'core/columns':
                //print_r($source);
                break;

            case 'core/heading':
                //print_r($block);
                break;

            case 'core/image':
                if ($parent && $parent->name === 'core/column') {
                    // Absolute width needed by Outlook
                    $block['attrs']['w'] = floor(self::$email_width * $parent->parsed_block['attrs']['width'] / 100);
                }
                break;

            case 'core/paragraph':
                // ???
                $fontSize = $block['attrs']['fontSize'] ?? '';
                if ($fontSize) {
                    unset($block['attrs']['fontSize']);
                }
                //print_r($block);
                break;
            case 'core/buttons':
                // Removes the outer DIV
                $block['innerContent'][0] = '';
                $block['innerContent'][count($block['innerContent']) - 1] = '';
        }


        return $block;
    }

    static function render($email) {

        add_filter('render_block', [self::class, 'render_block'], 999, 2);
        add_filter('render_block_data', [self::class, 'render_block_data'], 999, 3);

        $b = '<!DOCTYPE html>' . "\n";
        $b .= '<html xmlns="https://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">' . "\n";

        $b .= '<head>';
        $b .= '<style>';
        $b .= file_get_contents(__DIR__ . '/../assets/email.css');
        $b .= self::render_css();

        $b .= "</style>\n";

        $b .= "</head>\n";

        $b .= "<body>\n";

        $b .= '<div style="font-size: 10px; font-family: sans-serif; display: none;">' . wp_strip_all_tags($email->post_excerpt) . '</div>';

//        $b .= '<table class="container" border="0" cellpadding="0" align="center" cellspacing="0" width="100%" style="width: 100%!important; max-width: ' . (self::$email_width + 50) . 'px!important">';
//        $b .= "<tr>";
//        $b .= "<td>\n\n";
        // Probabilmente conviene estrarre i blocchi con il parse_blocks() e poi
        // processarli... vedi Fluent CRM
        $b .= do_blocks($email->post_content);
//        $b .= "</td>\n</tr>\n</table>\n";

        $b .= "</body>\n</html>";

        return $b;
    }
}

DispatcherGutenberg::$debug = isset(Dispatcher::$settings['gutenberg_debug']);
DispatcherGutenberg::init();

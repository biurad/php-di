<?php

declare(strict_types=1);

/*
 * This file is part of DivineNii opensource projects.
 *
 * PHP version 7.4 and above required
 *
 * @author    Divine Niiquaye Ibok <divineibok@gmail.com>
 * @copyright 2021 DivineNii (https://divinenii.com/)
 * @license   https://opensource.org/licenses/BSD-3-Clause License
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$header = <<<'EOF'
This file is part of DivineNii opensource projects.

PHP version 7.4 and above required

@author    Divine Niiquaye Ibok <divineibok@gmail.com>
@copyright 2021 DivineNii (https://divinenii.com/)
@license   https://opensource.org/licenses/BSD-3-Clause License

For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
EOF;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setFinder((new PhpCsFixer\Finder())->append([__FILE__]))
    ->setRules([
        '@PSR12' => true,
        //'DoctrineAnnotation' => true,
        'header_comment' => ['header' => $header],
        'general_phpdoc_tag_rename' => [
            'replacements' => [
                'inheritDocs' => 'inheritDoc',
                'inheritDoc' => 'inheritdoc',
            ],
        ],
        'backtick_to_shell_exec' => true,
        'no_mixed_echo_print' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_trailing_comma_in_singleline_array' => true,
        'no_whitespace_before_comma_in_array' => true,
        'normalize_index_brace' => true,
        'trailing_comma_in_multiline' => true,
        'trim_array_spaces' => true,
        'whitespace_after_comma_in_array' => true,
        'native_function_type_declaration_casing' => true,
        'braces' => [
            'allow_single_line_anonymous_class_with_empty_body' => true,
            'allow_single_line_closure' => true,
        ],
        'magic_constant_casing' => true,
        'magic_method_casing' => true,
        'native_function_casing' => true,
        'cast_spaces' => true,
        'no_short_bool_cast' => true,
        'no_unset_cast' => true,
        'short_scalar_cast' => true,
        'class_attributes_separation' => true,
        'protected_to_private' => false,
        'no_empty_comment' => true,
        'single_line_comment_style' => true,
        'native_constant_invocation' => true,
        'include' => true,
        'no_alternative_syntax' => true,
        'no_superfluous_elseif' => true,
        'no_trailing_comma_in_list_call' => true,
        'no_unneeded_control_parentheses' => true,
        'no_unneeded_curly_braces' => true,
        'no_useless_else' => true,
        //'switch_continue_to_break' => true,
        'yoda_style' => true,
        'combine_nested_dirname' => true,
        'function_typehint_space' => true,
        'lambda_not_used_import' => true,
        'native_function_invocation' => [
            'include' => ['@compiler_optimized', '@all'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
        //'single_line_throw' => true,
        //'use_arrow_functions' => true,
        'void_return' => true,
        //'global_namespace_import' => true,
        'no_unused_imports' => true,
        'single_import_per_statement' => false,
        'combine_consecutive_issets' => true,
        'combine_consecutive_unsets' => true,
        'dir_constant' => true,
        'function_to_constant' => true,
        'is_null' => true,
        'no_unset_on_property' => true,
        'single_space_after_construct' => true,
        'clean_namespace' => true,
        'no_leading_namespace_whitespace' => true,
        'binary_operator_spaces' => true,
        'concat_space' => ['spacing' => 'one'],
        'increment_style' => true,
        'object_operator_without_whitespace' => true,
        'standardize_increment' => true,
        'standardize_not_equals' => true,
        'ternary_to_null_coalescing' => true,
        'unary_operator_spaces' => true,
        'echo_tag_syntax' => true,
        'linebreak_after_opening_tag' => true,
        'align_multiline_comment' => true,
        'no_blank_lines_after_phpdoc' => true,
        'php_unit_construct' => true,
        'php_unit_fqcn_annotation' => true,
        'php_unit_method_casing' => true,
        '@PHPUnit84Migration:risky' => true,
        'no_empty_phpdoc' => true,
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
            'allow_unused_params' => true,
        ],
        'phpdoc_align' => true,
        'phpdoc_annotation_without_dot' => true,
        'phpdoc_indent' => true,
        'phpdoc_inline_tag_normalizer' => true,
        'phpdoc_no_access' => true,
        'phpdoc_no_empty_return' => true,
        'phpdoc_no_package' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_order' => true,
        'phpdoc_return_self_reference' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_summary' => true,
        'phpdoc_tag_type' => [
            'tags' => [
                'inheritdoc' => 'inline',
            ],
        ],
        'phpdoc_to_comment' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_types_order' => [
            'null_adjustment' => 'always_last',
            'sort_algorithm' => 'none',
        ],
        'phpdoc_var_without_name' => true,
        'return_assignment' => true,
        //'simplified_null_return' => true,
        //'no_empty_statement' => true,
        //'no_singleline_whitespace_before_semicolons' => true,
        'semicolon_after_instruction' => true,
        'space_after_semicolon' => [
            'remove_in_empty_for_expressions' => true,
        ],
        'declare_strict_types' => true,
        'single_quote' => true,
        'array_indentation' => true,
        'blank_line_before_statement' => [
            'statements' => [
                'break',
                'continue',
                'declare',
                'do',
                'for',
                'foreach',
                'if',
                'include',
                'include_once',
                'require',
                'require_once',
                'return',
                'switch',
                'throw',
                'try',
                'while',
                'yield',
            ],
        ],
        'no_extra_blank_lines' => true,
        'no_spaces_around_offset' => true,
        'nullable_type_declaration_for_default_null_value' => ['use_nullable_type_declaration' => false],

        // Risky standard
        //'@PSR12:risky' => true,
        //'group_import' => true,
        //'ordered_traits' => true,
        //'self_accessor' => true,
        //'array_push' => true,
        //'logical_operators' => true,
        //'set_type_to_cast' => true,
        //'modernize_types_casting' => true,
        //'no_null_property_initialization' => true,
        //'no_php4_constructor' => true,
        //'no_unneeded_final_method' => true,
        //'psr_autoloading' => true,
        //'non_printable_character' => ['use_escape_sequences_in_strings' => true]
    ]);

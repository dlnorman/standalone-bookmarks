<?php
/**
 * Simple markdown parser without external dependencies
 * Supports: headers, bold, italic, links, code blocks, inline code, lists, blockquotes
 */
function parseMarkdown($text) {
    if (empty($text)) return '';

    // Process code blocks first (before escaping, to preserve content)
    $codeBlocks = [];
    $text = preg_replace_callback('/```([a-z]*)\n(.*?)```/s', function($matches) use (&$codeBlocks) {
        $lang = $matches[1] ? ' class="language-' . $matches[1] . '"' : '';
        $placeholder = '___CODE_BLOCK_' . count($codeBlocks) . '___';
        $codeBlocks[$placeholder] = '<pre><code' . $lang . '>' . htmlspecialchars(trim($matches[2]), ENT_QUOTES, 'UTF-8') . '</code></pre>';
        return $placeholder;
    }, $text);

    // Split into lines for processing
    $lines = explode("\n", $text);
    $output = [];
    $inList = false;
    $inBlockquote = false;
    $inParagraph = false;
    $paragraphLines = [];

    foreach ($lines as $lineNum => $line) {
        $trimmed = trim($line);

        // Empty line - close any open paragraph
        if (empty($trimmed)) {
            if ($inParagraph) {
                $output[] = '<p>' . implode(' ', $paragraphLines) . '</p>';
                $paragraphLines = [];
                $inParagraph = false;
            }
            continue;
        }

        // Skip if line is a code block placeholder
        if (strpos($line, '___CODE_BLOCK_') !== false) {
            if ($inParagraph) {
                $output[] = '<p>' . implode(' ', $paragraphLines) . '</p>';
                $paragraphLines = [];
                $inParagraph = false;
            }
            $output[] = $line;
            continue;
        }

        // Headers (# through ######)
        if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches)) {
            if ($inParagraph) {
                $output[] = '<p>' . implode(' ', $paragraphLines) . '</p>';
                $paragraphLines = [];
                $inParagraph = false;
            }
            $level = strlen($matches[1]);
            $output[] = '<h' . $level . '>' . htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8') . '</h' . $level . '>';
            continue;
        }

        // Unordered lists (- or *)
        if (preg_match('/^[\*\-]\s+(.+)$/', $trimmed, $matches)) {
            if ($inParagraph) {
                $output[] = '<p>' . implode(' ', $paragraphLines) . '</p>';
                $paragraphLines = [];
                $inParagraph = false;
            }
            if (!$inList) {
                $output[] = '<ul>';
                $inList = true;
            }
            $output[] = '<li>' . htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') . '</li>';
            continue;
        } elseif ($inList === true) {
            $output[] = '</ul>';
            $inList = false;
        }

        // Ordered lists (1. 2. etc.)
        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $matches)) {
            if ($inParagraph) {
                $output[] = '<p>' . implode(' ', $paragraphLines) . '</p>';
                $paragraphLines = [];
                $inParagraph = false;
            }
            if (!$inList) {
                $output[] = '<ol>';
                $inList = 'ol';
            }
            $output[] = '<li>' . htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') . '</li>';
            continue;
        } elseif ($inList === 'ol') {
            $output[] = '</ol>';
            $inList = false;
        }

        // Blockquotes (including empty blockquote lines with just >)
        if (preg_match('/^>\s*(.*)$/', $trimmed, $matches)) {
            if ($inParagraph) {
                $output[] = '<p>' . implode(' ', $paragraphLines) . '</p>';
                $paragraphLines = [];
                $inParagraph = false;
            }
            if (!$inBlockquote) {
                $output[] = '<blockquote>';
                $inBlockquote = true;
            }
            // Add content (or empty line if no content)
            if (!empty($matches[1])) {
                $output[] = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') . '<br>';
            } else {
                $output[] = '<br>'; // Empty blockquote line creates a line break
            }
            continue;
        } elseif ($inBlockquote) {
            $output[] = '</blockquote>';
            $inBlockquote = false;
        }

        // Horizontal rules
        if (preg_match('/^(\*\*\*|---|___)$/', $trimmed)) {
            if ($inParagraph) {
                $output[] = '<p>' . implode(' ', $paragraphLines) . '</p>';
                $paragraphLines = [];
                $inParagraph = false;
            }
            $output[] = '<hr>';
            continue;
        }

        // Regular line - add to paragraph
        $inParagraph = true;
        $paragraphLines[] = htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8');
    }

    // Close any open paragraph
    if ($inParagraph) {
        $output[] = '<p>' . implode(' ', $paragraphLines) . '</p>';
    }

    // Close any open lists or blockquotes
    if ($inList === 'ol') {
        $output[] = '</ol>';
    } elseif ($inList === true) {
        $output[] = '</ul>';
    }
    if ($inBlockquote) {
        $output[] = '</blockquote>';
    }

    $text = implode('', $output);

    // Restore code blocks first (before processing inline elements to avoid placeholder corruption)
    foreach ($codeBlocks as $placeholder => $codeBlock) {
        $text = str_replace($placeholder, $codeBlock, $text);
    }

    // Inline elements (process after block elements and code block restoration)
    // Note: Content is already HTML-escaped at this point, so we work with escaped HTML

    // Inline code (backticks) - process before bold/italic to avoid conflicts
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

    // Bold (**text** or __text__)
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);

    // Italic (*text* or _text_)
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/_(.+?)_/', '<em>$1</em>', $text);

    // Links [text](url) - URL needs to be unescaped for href attribute
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^\)]+)\)/', function($matches) {
        $text = $matches[1];
        $url = htmlspecialchars_decode($matches[2], ENT_QUOTES);
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
    }, $text);

    return $text;
}

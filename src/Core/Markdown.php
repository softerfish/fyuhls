<?php

namespace App\Core;

class Markdown {
    public static function toHtml(string $text): string {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $html = '';
        $inList = false;
        foreach ($lines as $line) {
            if (preg_match('/^#{2}\s+(.*)$/', $line, $m)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<h2>' . htmlspecialchars($m[1]) . '</h2>';
            } elseif (preg_match('/^#\s+(.*)$/', $line, $m)) {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<h1>' . htmlspecialchars($m[1]) . '</h1>';
            } elseif (preg_match('/^-\s+(.*)$/', $line, $m)) {
                if (!$inList) { $html .= '<ul>'; $inList = true; }
                $html .= '<li>' . htmlspecialchars($m[1]) . '</li>';
            } elseif (trim($line) === '') {
                if ($inList) { $html .= '</ul>'; $inList = false; }
            } else {
                if ($inList) { $html .= '</ul>'; $inList = false; }
                $html .= '<p>' . htmlspecialchars($line) . '</p>';
            }
        }
        if ($inList) { $html .= '</ul>'; }
        return $html;
    }
}

<?php
// 설문 문항 정의: index.html 안의 DATA 를 그대로 읽어 씀 (설문을 고치면 관리자 화면도 자동으로 맞춰짐)
if (!defined('ITP_APP')) { http_response_code(403); exit; }

function survey_def() {
    static $def = null;
    if ($def !== null) {
        return $def;
    }
    $html = @file_get_contents(__DIR__ . '/../index.html');
    $data = null;
    if ($html !== false && preg_match('/const DATA = (\{.*?\});\n/s', $html, $m)) {
        $data = json_decode($m[1], true);
    }
    $def = array('sections' => array(), 'q' => array(), 'version' => '');
    if (!$data) {
        return $def;
    }
    $def['sections'] = $data['SECTIONS'];
    foreach ($data['Q'] as $q) {
        $def['q'][$q['id']] = $q;
    }
    if (preg_match('/const VERSION = "([^"]*)"/', $html, $m)) {
        $def['version'] = $m[1];
    }
    return $def;
}

function survey_q($id) {
    $def = survey_def();
    return isset($def['q'][$id]) ? $def['q'][$id] : null;
}

/** 단일 선택 문항의 [값 => 보기] 목록 */
function survey_options($id) {
    $q = survey_q($id);
    $out = array();
    if ($q && isset($q['options']) && is_array($q['options'])) {
        foreach ($q['options'] as $o) {
            $out[$o['v']] = $o['l'];
        }
    }
    return $out;
}

function opt_label($options, $v) {
    foreach ((array)$options as $o) {
        if (is_array($o) && isset($o['v']) && (string)$o['v'] === (string)$v) {
            return $o['l'];
        }
    }
    return $v === null || $v === '' ? '' : '(' . $v . ')';
}

function status_label($s) {
    $map = array('complete' => '제출 완료', 'progress' => '작성 중', 'out' => '대상외 종료');
    return isset($map[$s]) ? $map[$s] : $s;
}

function rtype_label($v) {
    $map = array(1 => '기업 대표응답자', 2 => '일반 재직자');
    return isset($map[(int)$v]) ? $map[(int)$v] : '';
}

function num_or_alt($v, $alts, $unit) {
    if (!is_array($v)) {
        return '';
    }
    if (isset($v['n']) && $v['n'] !== '' && $v['n'] !== null) {
        return $v['n'] . $unit;
    }
    if (isset($v['alt']) && $v['alt'] !== null) {
        return opt_label($alts, $v['alt']);
    }
    return '';
}

/** 한 문항의 응답을 사람이 읽을 수 있는 줄 목록으로 */
function format_answer($q, $a) {
    if ($a === null) {
        return array();
    }
    $lines = array();
    switch ($q['type']) {
        case 'single':
            $s = opt_label(g($q, 'options', array()), g($a, 'v'));
            if (g($a, 'text', '') !== '' && g($a, 'text') !== null) {
                $s .= ' (' . $a['text'] . ')';
            }
            $lines[] = $s;
            break;
        case 'multi':
            foreach ((array)g($a, 'sel', array()) as $v) {
                $s = opt_label($q['options'], $v);
                foreach ($q['options'] as $o) {
                    if ((string)$o['v'] === (string)$v && !empty($o['t']) && g($a, 'text', '') !== '') {
                        $s .= ' (' . $a['text'] . ')';
                    }
                }
                $lines[] = '· ' . $s;
            }
            if (g($a, 'extra', '') !== '' && g($a, 'extra') !== null) {
                $lines[] = '추가 의견: ' . $a['extra'];
            }
            break;
        case 'parts':
            foreach ($q['parts'] as $p) {
                if (array_key_exists($p['k'], $a)) {
                    $lines[] = $p['label'] . ': ' . opt_label($p['options'], $a[$p['k']]);
                }
            }
            break;
        case 'numalt':
            $lines[] = num_or_alt($a, g($q, 'alts', array()), g($q, 'unit', ''));
            break;
        case 'nrows':
            foreach ($q['rows'] as $r) {
                if (isset($a[$r['k']])) {
                    $lines[] = $r['l'] . ': ' . num_or_alt($a[$r['k']], g($q, 'alts', array()), g($q, 'unit', ''));
                }
            }
            break;
        case 'lmatrix':
            foreach ($q['rows'] as $i => $label) {
                if (isset($a[$i]) || isset($a[(string)$i])) {
                    $lines[] = $label . ': ' . opt_label($q['cols'], $a[$i]);
                }
            }
            break;
        case 'pair':
            foreach ($q['rows'] as $i => $label) {
                $parts = array();
                foreach ($q['cols'] as $c) {
                    $col = g($a, $c[0], array());
                    if (is_array($col) && array_key_exists($i, $col)) {
                        $v = $col[$i];
                        $parts[] = $c[1] . ' ' . ($v === 'X' ? g($q, 'alt', '판단 어려움') : $v);
                    }
                }
                if ($parts) {
                    $lines[] = $label . ': ' . implode(' / ', $parts);
                }
            }
            break;
        case 'text':
            $lines[] = (string)g($a, 'text', '');
            break;
        case 'form':
            foreach ($q['fields'] as $f) {
                if (g($a, $f['k'], '') !== '') {
                    $lines[] = $f['l'] . ': ' . $a[$f['k']];
                }
            }
            break;
        default:
            $lines[] = json_encode($a, JSON_UNESCAPED_UNICODE);
    }
    return $lines;
}

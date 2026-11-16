<?php
/**
 * Build an accessible Word document (.docx) from simple HTML: real headings,
 * bulleted lists and tables with a repeating header row, a document title and
 * language set, and 14 point text for easier reading.
 * Handles: h2, h3, p, ul/ol/li, table/tr/th/td, strong/b, em/i, a, br.
 */

declare(strict_types=1);

function docx_esc(string $s): string
{
    // Control characters aren't allowed in XML at all; Word refuses the file if one slips in from pasted text.
    $s = (string) preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Runs of text for the contents of an element, keeping bold and italic. */
function docx_runs(DOMNode $node, bool $bold = false, bool $italic = false): string
{
    $out = '';
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $text = preg_replace('/\s+/u', ' ', $child->wholeText);
            if ($text === '') {
                continue;
            }
            $props = ($bold ? '<w:b/>' : '') . ($italic ? '<w:i/>' : '');
            $out .= '<w:r>' . ($props ? "<w:rPr>$props</w:rPr>" : '') . '<w:t xml:space="preserve">' . docx_esc($text) . '</w:t></w:r>';
        } elseif ($child instanceof DOMElement) {
            $tag = strtolower($child->tagName);
            if ($tag === 'br') {
                $out .= '<w:r><w:br/></w:r>';
            } elseif ($tag === 'a') {
                $href = $child->getAttribute('href');
                $out .= docx_runs($child, $bold, $italic) . ($href !== '' && !str_starts_with($href, '#') ? docx_runs_text(' (' . $href . ')') : '');
            } else {
                $out .= docx_runs($child, $bold || in_array($tag, ['strong', 'b', 'th'], true), $italic || in_array($tag, ['em', 'i'], true));
            }
        }
    }
    return $out;
}

function docx_runs_text(string $text): string
{
    return '<w:r><w:t xml:space="preserve">' . docx_esc($text) . '</w:t></w:r>';
}

function docx_para(string $runs, string $style = ''): string
{
    return '<w:p>' . ($style !== '' ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '') . $runs . '</w:p>';
}

function docx_blocks(DOMNode $parent): string
{
    $out = '';
    foreach ($parent->childNodes as $node) {
        if ($node instanceof DOMText) {
            if (trim($node->wholeText) !== '') {
                $out .= docx_para(docx_runs_text(trim($node->wholeText)));
            }
            continue;
        }
        if (!$node instanceof DOMElement) {
            continue;
        }
        switch (strtolower($node->tagName)) {
            case 'h1':
            case 'h2':
                $out .= docx_para(docx_runs($node), 'Heading2');
                break;
            case 'h3':
            case 'h4':
                $out .= docx_para(docx_runs($node), 'Heading3');
                break;
            case 'ul':
            case 'ol':
                $numId = strtolower($node->tagName) === 'ol' ? 2 : 1;
                foreach ($node->childNodes as $li) {
                    if ($li instanceof DOMElement && strtolower($li->tagName) === 'li') {
                        $out .= '<w:p><w:pPr><w:pStyle w:val="ListParagraph"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $numId . '"/></w:numPr></w:pPr>' . docx_runs($li) . '</w:p>';
                    }
                }
                break;
            case 'table':
                $out .= docx_table($node);
                break;
            case 'div':
            case 'section':
            case 'blockquote':
                $out .= docx_blocks($node);
                break;
            default:
                $out .= docx_para(docx_runs($node));
        }
    }
    return $out;
}

function docx_table(DOMElement $table): string
{
    $rows = [];
    foreach ($table->getElementsByTagName('tr') as $tr) {
        $cells = [];
        $isHeader = false;
        foreach ($tr->childNodes as $cell) {
            if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['th', 'td'], true)) {
                $cells[] = $cell;
                $isHeader = $isHeader || ($tr->parentNode instanceof DOMElement && strtolower($tr->parentNode->tagName) === 'thead');
            }
        }
        if ($cells) {
            $rows[] = [$cells, $isHeader];
        }
    }
    if (!$rows) {
        return '';
    }
    $cols = max(array_map(fn($r) => count($r[0]), $rows));
    $width = (int) floor(9360 / $cols);
    $out = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="5000" w:type="pct"/>'
        . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="0" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/></w:tblPr>'
        . '<w:tblGrid>' . str_repeat('<w:gridCol w:w="' . $width . '"/>', $cols) . '</w:tblGrid>';
    foreach ($rows as [$cells, $isHeader]) {
        $out .= '<w:tr>' . ($isHeader ? '<w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>' : '<w:trPr><w:cantSplit/></w:trPr>');
        foreach ($cells as $cell) {
            $out .= '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/></w:tcPr>'
                . '<w:p><w:pPr><w:spacing w:before="60" w:after="60"/></w:pPr>' . docx_runs($cell, $isHeader) . '</w:p></w:tc>';
        }
        $out .= '</w:tr>';
    }
    return $out . '</w:tbl>' . docx_para('');
}

/** Returns the .docx file contents. */
function html_to_docx(string $title, string $html, string $intro = '', string $lang = 'en', bool $large = false): string
{
    // Sizes are in half-points: 28 is 14 point; large print is 24 point text with bigger headings.
    [$text, $h1, $h2, $h3] = $large ? [48, 64, 56, 52] : [28, 44, 34, 30];
    $langTag = $lang === 'es' ? 'es-US' : 'en-US';
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $root = $dom->getElementById('root');

    $body = docx_para(docx_runs_text($title), 'Heading1')
        . ($intro !== '' ? docx_para(docx_runs_text($intro)) : '')
        . docx_blocks($root)
        . docx_para(docx_runs_text(resource_footer_text($lang)))
        . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1080" w:right="1080" w:bottom="1080" w:left="1080" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>';

    $W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . "<w:document $W><w:body>$body</w:body></w:document>";

    $heading = fn(string $id, string $name, int $level, int $size) => '<w:style w:type="paragraph" w:styleId="' . $id . '"><w:name w:val="' . $name . '"/>'
        . '<w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:uiPriority w:val="9"/><w:qFormat/>'
        . '<w:pPr><w:keepNext/><w:spacing w:before="360" w:after="120"/><w:outlineLvl w:val="' . ($level - 1) . '"/></w:pPr>'
        . '<w:rPr><w:b/><w:color w:val="3B2A80"/><w:sz w:val="' . $size . '"/></w:rPr></w:style>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . "<w:styles $W>"
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="' . $text . '"/><w:szCs w:val="' . $text . '"/><w:lang w:val="' . $langTag . '"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:spacing w:after="160" w:line="300" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
        . $heading('Heading1', 'heading 1', 1, $h1) . $heading('Heading2', 'heading 2', 2, $h2) . $heading('Heading3', 'heading 3', 3, $h3)
        . '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:pPr><w:ind w:left="720"/><w:spacing w:after="80"/></w:pPr></w:style>'
        . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/><w:tblPr><w:tblBorders>'
        . '<w:top w:val="single" w:sz="8" w:color="444444"/><w:left w:val="single" w:sz="8" w:color="444444"/><w:bottom w:val="single" w:sz="8" w:color="444444"/>'
        . '<w:right w:val="single" w:sz="8" w:color="444444"/><w:insideH w:val="single" w:sz="4" w:color="444444"/><w:insideV w:val="single" w:sz="4" w:color="444444"/>'
        . '</w:tblBorders><w:tblCellMar><w:left w:w="108" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
        . '</w:styles>';

    $level = fn(string $fmt, string $text) => '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="' . $fmt . '"/><w:lvlText w:val="' . $text . '"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>';
    $numbering = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . "<w:numbering $W>"
        . '<w:abstractNum w:abstractNumId="0">' . $level('bullet', '•') . '</w:abstractNum>'
        . '<w:abstractNum w:abstractNumId="1">' . $level('decimal', '%1.') . '</w:abstractNum>'
        . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num><w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>'
        . '</w:numbering>';

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . docx_esc($title) . '</dc:title><dc:creator>' . docx_esc(legal_name()) . '</dc:creator><dc:language>' . $langTag . '</dc:language>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>';
    $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . "<w:settings $W><w:displayBackgroundShape/></w:settings>";

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
            . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
        'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/></Relationships>',
        'word/document.xml' => $document,
        'word/styles.xml' => $styles,
        'word/numbering.xml' => $numbering,
        'word/settings.xml' => $settings,
        'docProps/core.xml' => $core,
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the Word document.');
    }
    foreach ($files as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();
    $data = (string) file_get_contents($tmp);
    unlink($tmp);
    return $data;
}

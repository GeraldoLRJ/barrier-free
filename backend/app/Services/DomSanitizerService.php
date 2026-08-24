<?php

namespace App\Services;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;

class DomSanitizerService
{
    /**
     * Tags que devem ser completamente removidas (tag + conteúdo).
     */
    protected array $removeTags = [
        'script', 'style', 'noscript', 'iframe',
        'object', 'embed', 'applet', 'link', 'meta', 'head',
    ];

    /**
     * Tags semânticas cujo marcador será preservado na saída textual.
     * Formato: tag => tipo do marcador
     */
    protected array $semanticTags = [
        'h1' => 'Título 1',
        'h2' => 'Título 2',
        'h3' => 'Título 3',
        'h4' => 'Título 4',
        'h5' => 'Título 5',
        'h6' => 'Título 6',
        'nav' => 'Navegação',
        'main' => 'Conteúdo principal',
        'header' => 'Cabeçalho',
        'footer' => 'Rodapé',
        'aside' => 'Conteúdo lateral',
        'section' => 'Seção',
        'article' => 'Artigo',
    ];

    /**
     * Limite máximo de caracteres do conteúdo sanitizado
     * para enviar ao modelo de IA.
     */
    protected int $maxLength = 15000;

    /**
     * Sanitiza o conteúdo HTML removendo elementos irrelevantes e perigosos,
     * preservando estrutura semântica e atributos de acessibilidade
     * em um formato textual estruturado para a IA.
     */
    public function sanitize(string $htmlContent): string
    {
        Log::info('DomSanitizer: tamanho do HTML bruto recebido: ' . mb_strlen($htmlContent) . ' caracteres');

        // Carregar o HTML com DOMDocument
        $dom = new DOMDocument();

        // Suprimir warnings de HTML malformado e converter encoding
        $wrapped = '<html><head><meta charset="UTF-8"></head><body>' . $htmlContent . '</body></html>';
        @$dom->loadHTML(mb_convert_encoding($wrapped, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR | LIBXML_NOWARNING);

        // 1. Remover tags perigosas/irrelevantes via DOM traversal
        $this->removeUnsafeTags($dom);

        // 2. Remover elementos ocultos (hidden, display:none, aria-hidden)
        $this->removeHiddenElements($dom);

        // 3. Converter o DOM limpo em texto estruturado com marcadores
        $body = $dom->getElementsByTagName('body')->item(0);

        if (!$body) {
            Log::warning('DomSanitizer: não foi possível encontrar o body no DOM');
            return '';
        }

        $structuredText = $this->convertNodeToText($body);

        // 4. Limpar espaços excessivos
        $structuredText = preg_replace('/[ \t]+/', ' ', $structuredText) ?? $structuredText;
        $structuredText = preg_replace('/\n{3,}/', "\n\n", $structuredText) ?? $structuredText;
        $structuredText = trim($structuredText);

        Log::info('DomSanitizer: tamanho do conteúdo estruturado: ' . mb_strlen($structuredText) . ' caracteres');

        // 5. Truncar para o limite máximo
        if (mb_strlen($structuredText) > $this->maxLength) {
            $structuredText = mb_substr($structuredText, 0, $this->maxLength) . '... [conteúdo truncado]';
        }

        return $structuredText;
    }

    /**
     * Remove tags perigosas e irrelevantes do DOM (tag + todo seu conteúdo).
     */
    protected function removeUnsafeTags(DOMDocument $dom): void
    {
        foreach ($this->removeTags as $tagName) {
            $elements = $dom->getElementsByTagName($tagName);
            // Coletar antes de remover para não corromper o iterator
            $toRemove = [];
            for ($i = 0; $i < $elements->length; $i++) {
                $toRemove[] = $elements->item($i);
            }
            foreach ($toRemove as $element) {
                $element->parentNode?->removeChild($element);
            }
        }
    }

    /**
     * Remove elementos ocultos do DOM (aria-hidden="true", hidden attribute,
     * display:none/visibility:hidden inline style).
     */
    protected function removeHiddenElements(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);

        // aria-hidden="true"
        $hidden = $xpath->query('//*[@aria-hidden="true"]');
        $toRemove = [];
        foreach ($hidden as $node) {
            $toRemove[] = $node;
        }

        // atributo hidden
        $hiddenAttr = $xpath->query('//*[@hidden]');
        foreach ($hiddenAttr as $node) {
            $toRemove[] = $node;
        }

        // display:none ou visibility:hidden no style inline
        $allElements = $xpath->query('//*[@style]');
        foreach ($allElements as $node) {
            $style = $node->getAttribute('style');
            if (
                preg_match('/display\s*:\s*none/i', $style) ||
                preg_match('/visibility\s*:\s*hidden/i', $style)
            ) {
                $toRemove[] = $node;
            }
        }

        foreach ($toRemove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * Converte um nó DOM e seus filhos em texto estruturado com marcadores
     * de acessibilidade, preservando links, imagens, formulários, etc.
     */
    protected function convertNodeToText(DOMNode $node): string
    {
        // Nó de texto puro
        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent;
        }

        // Nó de comentário — ignorar
        if ($node->nodeType === XML_COMMENT_NODE) {
            return '';
        }

        // Só processar elementos
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tagName = strtolower($node->nodeName);
        $childrenText = $this->processChildren($node);

        // --- Tags especiais com marcadores ---

        // SVG: extrair apenas texto acessível (<title>, <desc>, <text>)
        if ($tagName === 'svg') {
            return $this->extractSvgText($node);
        }

        // Imagens
        if ($tagName === 'img') {
            return $this->convertImg($node);
        }

        // Links
        if ($tagName === 'a') {
            return $this->convertLink($node, $childrenText);
        }

        // Botões
        if ($tagName === 'button') {
            return $this->convertButton($node, $childrenText);
        }

        // Inputs e campos de formulário
        if (in_array($tagName, ['input', 'textarea', 'select'])) {
            return $this->convertFormField($node);
        }

        // Formulários
        if ($tagName === 'form') {
            $label = $this->getAccessibleName($node);
            $formLabel = $label ? " ({$label})" : '';
            return "\n[Formulário{$formLabel}]\n{$childrenText}\n[/Formulário]\n";
        }

        // Tabelas
        if ($tagName === 'table') {
            return $this->convertTable($node);
        }

        // Listas
        if ($tagName === 'ul' || $tagName === 'ol') {
            return "\n{$childrenText}\n";
        }

        if ($tagName === 'li') {
            return "• {$childrenText}\n";
        }

        // Tags semânticas (headings, nav, main, header, footer, etc.)
        if (isset($this->semanticTags[$tagName])) {
            $marker = $this->semanticTags[$tagName];
            $role = $this->getRole($node);
            if ($role && !str_starts_with($tagName, 'h')) {
                $marker = $role;
            }
            // Headings ficam em linha; landmarks ganham bloco
            if (str_starts_with($tagName, 'h')) {
                return "\n[{$marker}] {$childrenText}\n";
            }
            return "\n[{$marker}]\n{$childrenText}\n[/{$marker}]\n";
        }

        // Elementos com role ARIA explícito e sem tag semântica mapeada
        $role = $this->getRole($node);
        if ($role && !in_array($role, ['presentation', 'none'])) {
            return "\n[{$role}]\n{$childrenText}\n";
        }

        // Quebras de linha
        if ($tagName === 'br') {
            return "\n";
        }

        // Tags de bloco comuns — inserir quebra de linha
        if (in_array($tagName, ['div', 'p', 'blockquote', 'figure', 'figcaption', 'details', 'summary'])) {
            return "\n{$childrenText}\n";
        }

        // Qualquer outra tag — retornar conteúdo dos filhos sem marcador
        return $childrenText;
    }

    /**
     * Processa todos os nós filhos de um elemento e concatena o texto.
     */
    protected function processChildren(DOMNode $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->convertNodeToText($child);
        }
        return $text;
    }

    /**
     * Converte uma tag <img> em marcador textual com alt text.
     */
    protected function convertImg(DOMNode $node): string
    {
        $alt = $node->getAttribute('alt') ?? '';
        $ariaLabel = $node->getAttribute('aria-label') ?? '';
        $title = $node->getAttribute('title') ?? '';

        $description = $alt ?: $ariaLabel ?: $title;

        if (empty(trim($description))) {
            return ''; // Imagem decorativa sem descrição — omitir
        }

        return " [Imagem: {$description}] ";
    }

    /**
     * Converte uma tag <a> em marcador textual com texto e destino.
     */
    protected function convertLink(DOMNode $node, string $childrenText): string
    {
        $href = $node->getAttribute('href') ?? '';
        $ariaLabel = $node->getAttribute('aria-label') ?? '';
        $linkText = trim($ariaLabel ?: $childrenText);

        if (empty($linkText) && empty($href)) {
            return '';
        }

        // Ignorar links de âncora vazia ou javascript:void
        if (empty($href) || $href === '#' || str_starts_with($href, 'javascript:')) {
            return $linkText ? " [Link: {$linkText}] " : '';
        }

        return " [Link: {$linkText} -> {$href}] ";
    }

    /**
     * Converte uma tag <button> em marcador textual.
     */
    protected function convertButton(DOMNode $node, string $childrenText): string
    {
        $ariaLabel = $node->getAttribute('aria-label') ?? '';
        $title = $node->getAttribute('title') ?? '';
        $label = trim($ariaLabel ?: $title ?: $childrenText);

        if (empty($label)) {
            return '';
        }

        return " [Botão: {$label}] ";
    }

    /**
     * Converte campos de formulário (input, textarea, select) em marcadores.
     */
    protected function convertFormField(DOMNode $node): string
    {
        $tagName = strtolower($node->nodeName);
        $type = $node->getAttribute('type') ?: 'text';
        $ariaLabel = $node->getAttribute('aria-label') ?? '';
        $placeholder = $node->getAttribute('placeholder') ?? '';
        $title = $node->getAttribute('title') ?? '';
        $name = $node->getAttribute('name') ?? '';
        $value = $node->getAttribute('value') ?? '';

        // Ignorar campos hidden
        if ($type === 'hidden') {
            return '';
        }

        $label = trim($ariaLabel ?: $placeholder ?: $title ?: $name);

        if ($tagName === 'select') {
            $options = [];
            foreach ($node->getElementsByTagName('option') as $option) {
                $optText = trim($option->textContent);
                if (!empty($optText)) {
                    $options[] = $optText;
                }
            }
            $optionsStr = !empty($options) ? ' (opções: ' . implode(', ', array_slice($options, 0, 10)) . ')' : '';
            return " [Campo seleção: {$label}{$optionsStr}] ";
        }

        if ($tagName === 'textarea') {
            return " [Campo texto longo: {$label}] ";
        }

        // Input types
        $typeLabels = [
            'text' => 'Campo texto',
            'email' => 'Campo e-mail',
            'password' => 'Campo senha',
            'search' => 'Campo busca',
            'tel' => 'Campo telefone',
            'url' => 'Campo URL',
            'number' => 'Campo número',
            'date' => 'Campo data',
            'checkbox' => 'Caixa de seleção',
            'radio' => 'Opção',
            'submit' => 'Botão enviar',
            'reset' => 'Botão limpar',
            'file' => 'Selecionar arquivo',
        ];

        $typeLabel = $typeLabels[$type] ?? "Campo {$type}";

        // Para submit/reset, usar value como label se disponível
        if (in_array($type, ['submit', 'reset']) && !empty($value)) {
            $label = $value;
        }

        if (empty($label) && in_array($type, ['submit', 'reset'])) {
            $label = $type === 'submit' ? 'Enviar' : 'Limpar';
        }

        return " [{$typeLabel}: {$label}] ";
    }

    /**
     * Converte uma tabela em texto estruturado preservando linhas e colunas.
     */
    protected function convertTable(DOMNode $node): string
    {
        $caption = '';
        $ariaLabel = $node->getAttribute('aria-label') ?? '';

        // Buscar <caption>
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'caption') {
                $caption = trim($child->textContent);
                break;
            }
        }

        $tableLabel = $ariaLabel ?: $caption;
        $result = "\n[Tabela" . ($tableLabel ? ": {$tableLabel}" : '') . "]\n";

        // Extrair linhas da tabela (thead, tbody, tfoot ou direto)
        $rows = $node->getElementsByTagName('tr');
        $rowCount = 0;

        for ($i = 0; $i < $rows->length && $rowCount < 50; $i++) {
            $row = $rows->item($i);
            $cells = [];

            foreach ($row->childNodes as $cell) {
                if ($cell->nodeType !== XML_ELEMENT_NODE) continue;
                $cellTag = strtolower($cell->nodeName);
                if ($cellTag === 'th' || $cellTag === 'td') {
                    $cells[] = trim($cell->textContent);
                }
            }

            if (!empty($cells)) {
                $result .= implode(' | ', $cells) . "\n";
                $rowCount++;
            }
        }

        $result .= "[/Tabela]\n";
        return $result;
    }

    /**
     * Extrai texto acessível de dentro de um SVG (<title>, <desc>, <text>).
     */
    protected function extractSvgText(DOMNode $node): string
    {
        $texts = [];

        foreach (['title', 'desc', 'text'] as $tagName) {
            $elements = $node->getElementsByTagName($tagName);
            for ($i = 0; $i < $elements->length; $i++) {
                $content = trim($elements->item($i)->textContent);
                if (!empty($content)) {
                    $texts[] = $content;
                }
            }
        }

        if (empty($texts)) {
            // Verificar aria-label no próprio SVG
            $ariaLabel = $node->getAttribute('aria-label') ?? '';
            if (!empty(trim($ariaLabel))) {
                return " [Ícone: {$ariaLabel}] ";
            }
            return ''; // SVG decorativo — omitir
        }

        return ' [Ícone: ' . implode(' — ', $texts) . '] ';
    }

    /**
     * Retorna o nome acessível de um elemento (aria-label, title, ou aria-labelledby).
     */
    protected function getAccessibleName(DOMNode $node): string
    {
        $ariaLabel = $node->getAttribute('aria-label') ?? '';
        if (!empty(trim($ariaLabel))) {
            return trim($ariaLabel);
        }

        $title = $node->getAttribute('title') ?? '';
        if (!empty(trim($title))) {
            return trim($title);
        }

        return '';
    }

    /**
     * Retorna o role ARIA de um elemento, traduzido para português.
     */
    protected function getRole(DOMNode $node): string
    {
        $role = $node->getAttribute('role') ?? '';
        if (empty(trim($role))) {
            return '';
        }

        $roleTranslations = [
            'navigation' => 'Navegação',
            'main' => 'Conteúdo principal',
            'banner' => 'Banner',
            'contentinfo' => 'Informações do site',
            'complementary' => 'Conteúdo complementar',
            'search' => 'Busca',
            'form' => 'Formulário',
            'region' => 'Região',
            'alert' => 'Alerta',
            'dialog' => 'Diálogo',
            'tablist' => 'Lista de abas',
            'tab' => 'Aba',
            'tabpanel' => 'Painel de aba',
            'menu' => 'Menu',
            'menubar' => 'Barra de menu',
            'menuitem' => 'Item de menu',
            'toolbar' => 'Barra de ferramentas',
            'list' => 'Lista',
            'listitem' => 'Item de lista',
        ];

        return $roleTranslations[strtolower(trim($role))] ?? ucfirst(trim($role));
    }
}

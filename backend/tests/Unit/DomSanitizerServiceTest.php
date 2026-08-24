<?php

namespace Tests\Unit;

use App\Services\DomSanitizerService;
use Tests\TestCase;

class DomSanitizerServiceTest extends TestCase
{
    protected DomSanitizerService $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new DomSanitizerService();
    }

    // ==============================
    // Remoção de tags perigosas
    // ==============================

    public function test_remove_script_tags(): void
    {
        $html = '<div>Texto visível</div><script>alert("xss")</script><p>Mais texto</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('Texto visível', $result);
        $this->assertStringContainsString('Mais texto', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringNotContainsString('script', $result);
    }

    public function test_remove_style_tags(): void
    {
        $html = '<style>.hidden { display: none; }</style><p>Conteúdo real</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('Conteúdo real', $result);
        $this->assertStringNotContainsString('display', $result);
    }

    public function test_remove_iframe_tags(): void
    {
        $html = '<p>Antes</p><iframe src="https://malicious.com"></iframe><p>Depois</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('Antes', $result);
        $this->assertStringContainsString('Depois', $result);
        $this->assertStringNotContainsString('iframe', $result);
        $this->assertStringNotContainsString('malicious', $result);
    }

    // ==============================
    // Preservação de links
    // ==============================

    public function test_preserva_links_com_href(): void
    {
        $html = '<a href="https://exemplo.com/contato">Fale Conosco</a>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Link: Fale Conosco -> https://exemplo.com/contato]', $result);
    }

    public function test_preserva_link_com_aria_label(): void
    {
        $html = '<a href="/home" aria-label="Ir para página inicial">🏠</a>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Link: Ir para página inicial -> /home]', $result);
    }

    public function test_ignora_link_javascript_void(): void
    {
        $html = '<a href="javascript:void(0)">Clique aqui</a>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('javascript', $result);
        $this->assertStringContainsString('Clique aqui', $result);
    }

    // ==============================
    // Preservação de imagens com alt
    // ==============================

    public function test_preserva_imagem_com_alt(): void
    {
        $html = '<img src="foto.jpg" alt="Foto do produto em promoção">';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Imagem: Foto do produto em promoção]', $result);
    }

    public function test_omite_imagem_decorativa_sem_alt(): void
    {
        $html = '<img src="decorativa.png" alt=""><p>Texto ao lado</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('[Imagem:', $result);
        $this->assertStringContainsString('Texto ao lado', $result);
    }

    public function test_preserva_imagem_com_aria_label(): void
    {
        $html = '<img src="icon.svg" aria-label="Ícone de configurações">';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Imagem: Ícone de configurações]', $result);
    }

    // ==============================
    // Preservação de formulários
    // ==============================

    public function test_preserva_campos_de_formulario(): void
    {
        $html = '
        <form aria-label="Formulário de login">
            <input type="email" placeholder="Seu e-mail">
            <input type="password" placeholder="Sua senha">
            <button>Entrar</button>
        </form>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Formulário (Formulário de login)]', $result);
        $this->assertStringContainsString('[Campo e-mail: Seu e-mail]', $result);
        $this->assertStringContainsString('[Campo senha: Sua senha]', $result);
        $this->assertStringContainsString('[Botão: Entrar]', $result);
    }

    public function test_preserva_select_com_opcoes(): void
    {
        $html = '
        <select aria-label="Escolha o estado">
            <option>São Paulo</option>
            <option>Rio de Janeiro</option>
            <option>Minas Gerais</option>
        </select>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Campo seleção: Escolha o estado', $result);
        $this->assertStringContainsString('São Paulo', $result);
        $this->assertStringContainsString('Rio de Janeiro', $result);
    }

    public function test_preserva_textarea(): void
    {
        $html = '<textarea placeholder="Escreva sua mensagem"></textarea>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Campo texto longo: Escreva sua mensagem]', $result);
    }

    public function test_ignora_input_hidden(): void
    {
        $html = '<input type="hidden" name="csrf_token" value="abc123"><p>Visível</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('csrf_token', $result);
        $this->assertStringNotContainsString('abc123', $result);
        $this->assertStringContainsString('Visível', $result);
    }

    public function test_preserva_botao_submit_com_value(): void
    {
        $html = '<input type="submit" value="Cadastrar">';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Botão enviar: Cadastrar]', $result);
    }

    // ==============================
    // Preservação de headings
    // ==============================

    public function test_preserva_hierarquia_de_headings(): void
    {
        $html = '<h1>Título Principal</h1><h2>Subtítulo</h2><p>Parágrafo</p><h3>Sub-subtítulo</h3>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Título 1] Título Principal', $result);
        $this->assertStringContainsString('[Título 2] Subtítulo', $result);
        $this->assertStringContainsString('[Título 3] Sub-subtítulo', $result);
        $this->assertStringContainsString('Parágrafo', $result);
    }

    // ==============================
    // Preservação de landmarks e ARIA
    // ==============================

    public function test_preserva_nav_landmark(): void
    {
        $html = '<nav><a href="/home">Início</a><a href="/sobre">Sobre</a></nav>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Navegação]', $result);
        $this->assertStringContainsString('[Link: Início -> /home]', $result);
        $this->assertStringContainsString('[Link: Sobre -> /sobre]', $result);
    }

    public function test_preserva_main_landmark(): void
    {
        $html = '<main><p>Conteúdo principal da página</p></main>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Conteúdo principal]', $result);
        $this->assertStringContainsString('Conteúdo principal da página', $result);
    }

    public function test_preserva_role_aria_em_divs(): void
    {
        $html = '<div role="search"><input type="search" placeholder="Pesquisar"></div>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Busca]', $result);
        $this->assertStringContainsString('[Campo busca: Pesquisar]', $result);
    }

    public function test_preserva_botao_com_aria_label(): void
    {
        $html = '<button aria-label="Fechar modal">✕</button>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Botão: Fechar modal]', $result);
    }

    // ==============================
    // Preservação de listas
    // ==============================

    public function test_preserva_itens_de_lista(): void
    {
        $html = '<ul><li>Item A</li><li>Item B</li><li>Item C</li></ul>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('• Item A', $result);
        $this->assertStringContainsString('• Item B', $result);
        $this->assertStringContainsString('• Item C', $result);
    }

    // ==============================
    // Preservação de tabelas
    // ==============================

    public function test_preserva_tabela_com_dados(): void
    {
        $html = '
        <table aria-label="Tabela de preços">
            <tr><th>Plano</th><th>Preço</th></tr>
            <tr><td>Básico</td><td>R$ 29,90</td></tr>
            <tr><td>Pro</td><td>R$ 59,90</td></tr>
        </table>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Tabela: Tabela de preços]', $result);
        $this->assertStringContainsString('Plano | Preço', $result);
        $this->assertStringContainsString('Básico | R$ 29,90', $result);
        $this->assertStringContainsString('Pro | R$ 59,90', $result);
    }

    public function test_preserva_tabela_com_caption(): void
    {
        $html = '
        <table>
            <caption>Horários de atendimento</caption>
            <tr><td>Segunda</td><td>08h - 18h</td></tr>
        </table>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Tabela: Horários de atendimento]', $result);
    }

    // ==============================
    // SVG com texto acessível
    // ==============================

    public function test_extrai_texto_acessivel_de_svg(): void
    {
        $html = '<svg><title>Ícone de carrinho</title><desc>Carrinho de compras com 3 itens</desc></svg>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[Ícone:', $result);
        $this->assertStringContainsString('Ícone de carrinho', $result);
    }

    public function test_omite_svg_decorativo(): void
    {
        $html = '<svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg><p>Texto</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('[Ícone', $result);
        $this->assertStringContainsString('Texto', $result);
    }

    // ==============================
    // Remoção de elementos ocultos
    // ==============================

    public function test_remove_elementos_aria_hidden(): void
    {
        $html = '<p>Visível</p><div aria-hidden="true">Conteúdo oculto para leitores de tela</div>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('Visível', $result);
        $this->assertStringNotContainsString('oculto para leitores', $result);
    }

    public function test_remove_elementos_com_hidden_attribute(): void
    {
        $html = '<p>Aparece</p><div hidden>Não aparece</div>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('Aparece', $result);
        $this->assertStringNotContainsString('Não aparece', $result);
    }

    public function test_remove_elementos_display_none(): void
    {
        $html = '<p>Visível</p><span style="display: none;">Escondido via CSS</span>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('Visível', $result);
        $this->assertStringNotContainsString('Escondido via CSS', $result);
    }

    // ==============================
    // Truncamento e limites
    // ==============================

    public function test_trunca_conteudo_grande(): void
    {
        // Gerar HTML com mais de 15000 caracteres de texto
        $html = '<p>' . str_repeat('Texto de teste. ', 2000) . '</p>';
        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('[conteúdo truncado]', $result);
        $this->assertLessThanOrEqual(15100, mb_strlen($result)); // 15000 + margem para o sufixo
    }

    // ==============================
    // HTML malformado
    // ==============================

    public function test_lida_com_html_malformado(): void
    {
        $html = '<div><p>Parágrafo sem fechar<br><a href="/link">Link sem fechar<div>Outro div</div>';
        $result = $this->sanitizer->sanitize($html);

        // Deve processar sem erros e extrair conteúdo útil
        $this->assertStringContainsString('Parágrafo sem fechar', $result);
        $this->assertStringContainsString('[Link:', $result);
    }

    public function test_lida_com_html_vazio(): void
    {
        $result = $this->sanitizer->sanitize('');

        $this->assertIsString($result);
    }

    // ==============================
    // Cenário integrado realista
    // ==============================

    public function test_pagina_realista_preserva_todos_elementos(): void
    {
        $html = '
        <header>
            <nav>
                <a href="/">Início</a>
                <a href="/produtos">Produtos</a>
                <a href="/contato">Contato</a>
            </nav>
        </header>
        <main>
            <h1>Bem-vindo à Nossa Loja</h1>
            <p>Confira nossos produtos em destaque.</p>
            <img src="banner.jpg" alt="Banner de verão com 50% de desconto">
            <h2>Produtos</h2>
            <ul>
                <li>Camiseta Básica - R$ 49,90</li>
                <li>Calça Jeans - R$ 129,90</li>
            </ul>
            <form aria-label="Inscreva-se na newsletter">
                <input type="email" placeholder="Seu melhor e-mail">
                <button>Inscrever</button>
            </form>
        </main>
        <footer>
            <a href="/privacidade">Política de Privacidade</a>
        </footer>
        <script>console.log("tracking")</script>
        <style>.hidden{display:none}</style>';
        $result = $this->sanitizer->sanitize($html);

        // Estrutura semântica
        $this->assertStringContainsString('[Cabeçalho]', $result);
        $this->assertStringContainsString('[Navegação]', $result);
        $this->assertStringContainsString('[Conteúdo principal]', $result);
        $this->assertStringContainsString('[Rodapé]', $result);

        // Headings
        $this->assertStringContainsString('[Título 1] Bem-vindo à Nossa Loja', $result);
        $this->assertStringContainsString('[Título 2] Produtos', $result);

        // Links
        $this->assertStringContainsString('[Link: Início -> /]', $result);
        $this->assertStringContainsString('[Link: Produtos -> /produtos]', $result);
        $this->assertStringContainsString('[Link: Política de Privacidade -> /privacidade]', $result);

        // Imagem
        $this->assertStringContainsString('[Imagem: Banner de verão com 50% de desconto]', $result);

        // Formulário
        $this->assertStringContainsString('[Formulário (Inscreva-se na newsletter)]', $result);
        $this->assertStringContainsString('[Campo e-mail: Seu melhor e-mail]', $result);
        $this->assertStringContainsString('[Botão: Inscrever]', $result);

        // Listas
        $this->assertStringContainsString('• Camiseta Básica', $result);
        $this->assertStringContainsString('• Calça Jeans', $result);

        // Conteúdo perigoso removido
        $this->assertStringNotContainsString('tracking', $result);
        $this->assertStringNotContainsString('console.log', $result);
        $this->assertStringNotContainsString('display:none', $result);
    }
}

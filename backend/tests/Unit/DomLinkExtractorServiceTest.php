<?php

namespace Tests\Unit;

use App\Services\DomLinkExtractorService;
use PHPUnit\Framework\TestCase;

class DomLinkExtractorServiceTest extends TestCase
{
    protected DomLinkExtractorService $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new DomLinkExtractorService();
    }

    public function test_extrai_links_de_nav_e_header_mesmo_quando_main_tem_mais_de_60_links(): void
    {
        $mainLinks = '';
        for ($i = 1; $i <= 80; $i++) {
            $mainLinks .= "<a href=\"/noticia-{$i}\">Manchete da notícia número {$i}</a>";
        }

        $html = "
            <header>
                <nav>
                    <a href=\"/jogos\">Jogos</a>
                    <a href=\"/economia\">Economia</a>
                    <a href=\"/politica\">Política</a>
                </nav>
            </header>
            <main>{$mainLinks}</main>
        ";

        $links = $this->extractor->extract($html, 'https://g1.globo.com/');
        $texts = array_column($links, 'text');
        $urls  = array_column($links, 'url');

        $this->assertContains('Jogos', $texts);
        $this->assertContains('Economia', $texts);
        $this->assertContains('Política', $texts);
        $this->assertContains('https://g1.globo.com/jogos', $urls);
        $this->assertGreaterThan(80, count($links));
    }

    public function test_prioriza_heading_interno_e_separa_elementos_com_espaco(): void
    {
        $html = '
            <main>
                <a href="https://g1.globo.com/">
                    <h3>g1 - O portal de notícias da Globo</h3>
                    <div><span>g1</span><cite>https://g1.globo.com</cite></div>
                </a>
                <a href="/jogos/soletra"><span>Soletra</span><span>Jogue agora</span></a>
            </main>
        ';

        $links = $this->extractor->extract($html, 'https://www.google.com.br/search?q=noticias');

        $this->assertCount(2, $links);
        $this->assertSame('g1 - O portal de notícias da Globo', $links[0]['text']);
        $this->assertSame('Soletra Jogue agora', $links[1]['text']);
    }

    public function test_usa_aria_label_e_ignora_elementos_ocultos(): void
    {
        $html = '
            <nav>
                <a href="/jogos" aria-label="Jogos do G1"><svg><path d="M0 0"/></svg></a>
                <a href="/oculto-aria" aria-hidden="true">Link Oculto Aria</a>
                <a href="/oculto-style" style="display: none;">Link Oculto Style</a>
            </nav>
        ';

        $links = $this->extractor->extract($html, 'https://g1.globo.com/');

        $this->assertCount(1, $links);
        $this->assertSame('Jogos do G1', $links[0]['text']);
        $this->assertSame('https://g1.globo.com/jogos', $links[0]['url']);
    }

    public function test_deduplica_urls_com_e_sem_barra_final(): void
    {
        $html = '
            <nav>
                <a href="https://g1.globo.com/jogos">Jogos</a>
                <a href="https://g1.globo.com/jogos/">Jogos Duplicado</a>
            </nav>
        ';

        $links = $this->extractor->extract($html, 'https://g1.globo.com/');

        $this->assertCount(1, $links);
        $this->assertSame('Jogos', $links[0]['text']);
    }

    public function test_preserva_nome_do_site_fora_do_heading_e_ignora_modo_ia_no_google(): void
    {
        $html = '
            <div role="navigation">
                <a href="/search?q=valor+euro&amp;udm=50">Modo IA</a>
                <a href="/search?q=valor+euro&amp;tbm=nws">Notícias</a>
            </div>
            <main>
                <a href="https://wise.com/br/currency-converter/eur-to-brl-rate">
                    <h3>Converter Euro para Real brasileiro</h3>
                    <div><span>Wise</span><cite>https://wise.com › br</cite></div>
                </a>
                <a href="https://www.bcb.gov.br/conversao">
                    <h3>Conversor de Moedas</h3>
                    <div><span>Banco Central do Brasil</span><cite>https://www.bcb.gov.br</cite></div>
                </a>
            </main>
        ';

        $links = $this->extractor->extract($html, 'https://www.google.com.br/search?q=valor+euro');
        $texts = array_column($links, 'text');

        $this->assertNotContains('Modo IA', $texts);
        $this->assertSame('Converter Euro para Real brasileiro - Wise', $links[0]['text']);
        $this->assertSame('Conversor de Moedas - Banco Central do Brasil', $links[1]['text']);
        $this->assertSame('Notícias', $links[2]['text']);
    }
}

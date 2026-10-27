<?php

namespace Tests\Unit;

use App\Services\SearchSpeechFormatter;
use PHPUnit\Framework\TestCase;

class SearchSpeechFormatterTest extends TestCase
{
    protected SearchSpeechFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new SearchSpeechFormatter();
    }

    public function test_site_name_usa_dominio_registravel_e_mapa_de_nomes(): void
    {
        $this->assertSame('UOL', $this->formatter->siteName('https://economia.uol.com.br/noticias'));
        $this->assertSame('PUC Paraná', $this->formatter->siteName('https://www.pucpr.br/biblioteca'));
        $this->assertSame('Banco Central', $this->formatter->siteName('https://www.bcb.gov.br/'));
        $this->assertSame('Wise', $this->formatter->siteName('https://wise.com/br'));
        $this->assertSame('', $this->formatter->siteName('nao-e-url'));
    }

    public function test_short_title_remove_sufixos_de_site_e_simbolos(): void
    {
        $this->assertSame(
            'Sites Recomendados',
            $this->formatter->shortTitle('Sites Recomendados - Economia | PUCPR')
        );
        $this->assertSame(
            'UOL Economia',
            $this->formatter->shortTitle('UOL Economia: encontre orientações e notícias sobre economia')
        );
        $this->assertSame(
            'Os 6 melhores sites sobre finanças',
            $this->formatter->shortTitle('Os 6 melhores sites sobre finanças 💰')
        );
    }

    public function test_short_title_limita_tamanho_em_limite_de_palavra(): void
    {
        $long  = str_repeat('palavra ', 30);
        $short = $this->formatter->shortTitle($long);

        $this->assertLessThanOrEqual(70, mb_strlen($short));
        $this->assertStringEndsWith('palavra', $short);
    }

    public function test_build_sources_numera_a_partir_de_um(): void
    {
        $sources = $this->formatter->buildSources([
            ['title' => 'A | Site A', 'url' => 'https://a.com.br', 'description' => 'x'],
            ['title' => 'B título longo', 'url' => 'https://b.org', 'description' => 'y'],
        ]);

        $this->assertSame([1, 2], array_column($sources, 'number'));
        $this->assertArrayHasKey('site_name', $sources[0]);
        $this->assertArrayHasKey('spoken_title', $sources[0]);
        $this->assertArrayNotHasKey('description', $sources[0]);
    }

    public function test_build_speech_tem_estrutura_fixa_com_resposta_lista_e_instrucao(): void
    {
        $sources = $this->formatter->buildSources([
            ['title' => 'Sites Recomendados - Economia | PUCPR', 'url' => 'https://www.pucpr.br/x'],
            ['title' => 'UOL Economia: encontre orientações', 'url' => 'https://economia.uol.com.br'],
        ]);

        $speech = $this->formatter->buildSpeech('Há vários portais de economia.', $sources);

        $this->assertStringStartsWith('Há vários portais de economia.', $speech);
        $this->assertStringContainsString('Encontrei 2 opções.', $speech);
        $this->assertStringContainsString('Opção 1: PUC Paraná, Sites Recomendados.', $speech);
        // Título já contém o nome do site: não repete "UOL, UOL Economia"
        $this->assertStringContainsString('Opção 2: UOL Economia.', $speech);
        $this->assertStringContainsString('diga abrir opção seguido do número, de 1 a 2', $speech);
        $this->assertStringContainsString('repetir opções', $speech);
        $this->assertStringNotContainsString('Fonte', $speech);
    }

    public function test_build_speech_singular_e_sem_resultados(): void
    {
        $one = $this->formatter->buildSpeech('Ok.', $this->formatter->buildSources([
            ['title' => 'Único resultado', 'url' => 'https://exemplo.com'],
        ]));
        $this->assertStringContainsString('Encontrei 1 opção.', $one);
        $this->assertStringContainsString('diga abrir opção 1', $one);

        $this->assertSame('Somente resposta.', $this->formatter->buildSpeech('Somente resposta.', []));
    }

    public function test_build_list_only_nao_inclui_resposta(): void
    {
        $sources = $this->formatter->buildSources([
            ['title' => 'Primeiro resultado', 'url' => 'https://exemplo.com'],
        ]);

        $this->assertStringStartsWith('Encontrei 1 opção.', $this->formatter->buildListOnly($sources));
    }
}

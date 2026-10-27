// Testes das funções de navegação por opção do background.js.
// Executar: node --test extensao/tests/option-parsing.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const code = fs.readFileSync(path.join(dir, '..', 'background.js'), 'utf8');

const noop = () => {};
const store = {};
const sent = [];
const created = [];

const chromeStub = {
    runtime: { onMessage: { addListener: noop }, sendMessage: (m) => sent.push(m) },
    tabs: {
        onUpdated: { addListener: noop },
        query: async () => [{ id: 1, windowId: 1, url: 'https://exemplo.com' }],
        create: async (o) => created.push(o),
        update: async () => {},
    },
    windows: { onRemoved: { addListener: noop }, update: async () => {}, create: noop },
    commands: { onCommand: { addListener: noop } },
    storage: {
        local: { set: noop },
        session: {
            get: async (keys) => Object.fromEntries([].concat(keys).map((k) => [k, store[k]]).filter(([, v]) => v !== undefined)),
            set: async (p) => Object.assign(store, p),
            remove: async (keys) => [].concat(keys).forEach((k) => delete store[k]),
        },
    },
    scripting: { executeScript: async () => [] },
};

const ctx = vm.createContext({ chrome: chromeStub, console, URL, fetch: noop });
vm.runInContext(code, ctx);

const SOURCES = [
    { number: 1, site_name: 'PUC Paraná', spoken_title: 'Sites Recomendados', title: 'Sites Recomendados - Economia | PUCPR', url: 'https://www.pucpr.br/x' },
    { number: 2, site_name: 'Investidor Sardinha', spoken_title: 'Sites de investimentos', title: 'Sites de investimentos – Investidor Sardinha', url: 'https://investidorsardinha.r7.com/a' },
    { number: 3, site_name: 'Exemplo', spoken_title: '5 sites de economia', title: '5 sites de economia que todo investidor deve acompanhar', url: 'https://exemplo.com/b' },
    { number: 4, site_name: 'UOL', spoken_title: 'UOL Economia', title: 'UOL Economia: encontre orientações e notícias sobre economia', url: 'https://economia.uol.com.br' },
    { number: 5, site_name: 'Outro', spoken_title: 'Os 6 melhores sites sobre finanças', title: 'Os 6 melhores sites sobre finanças', url: 'https://outro.com.br/c' },
];

const run = (expr) => vm.runInContext(expr, ctx);

test('reconhece seleção por opção/fonte/resultado + número', () => {
    for (const cmd of ['abrir opção 4', 'abrir fonte 4', 'abrir resultado quatro', 'abrir a quarta', 'abrir 4', 'abrir o último']) {
        assert.equal(run(`isOptionSelectionCommand(${JSON.stringify(cmd)})`), true, cmd);
    }
});

test('não confunde nomes de site com seleção numérica', () => {
    for (const cmd of ['abrir uol', 'abrir notícias', 'abrir', 'abrir opção', 'abrir uol economia']) {
        assert.equal(run(`isOptionSelectionCommand(${JSON.stringify(cmd)})`), false, cmd);
    }
});

test('parseOptionNumber respeita a faixa e nunca escolhe outra opção em silêncio', () => {
    assert.equal(run(`parseOptionNumber('abrir opção 4', 5).index`), 3);
    assert.equal(run(`parseOptionNumber('abrir quinta opção', 5).index`), 4);
    assert.equal(run(`parseOptionNumber('abrir o último', 5).index`), 4);
    assert.equal(run(`parseOptionNumber('abrir opção 7', 5).outOfRange`), 7);
    assert.equal(run(`parseOptionNumber('abrir opção 0', 5).outOfRange`), 0);
});

test('"abrir" sozinho é detectado como comando vazio', () => {
    assert.equal(run(`isBareOpenCommand('abrir')`), true);
    assert.equal(run(`isBareOpenCommand('abrir opção')`), true);
    assert.equal(run(`isBareOpenCommand('abrir uol')`), false);
});

test('abrir por nome casa pelo site (UOL -> opção 4) e ignora títulos sem o site', () => {
    ctx.SOURCES = SOURCES;
    assert.equal(run(`matchSourceBySite('abrir uol', SOURCES).number`), 4);
    assert.equal(run(`matchSourceBySite('abrir puc paraná', SOURCES).number`), 1);
    // "notícias" aparece no título da opção 4, mas não é o nome do site: não deve sequestrar
    assert.equal(run(`matchSourceBySite('abrir notícias', SOURCES)`), null);
});

test('buildOptionsSpeech usa vocabulário "opção" e a faixa correta', () => {
    ctx.SOURCES = SOURCES;
    const text = run('buildOptionsSpeech(SOURCES)');
    assert.match(text, /Você tem 5 opções\./);
    assert.match(text, /Opção 4: UOL Economia\./); // título já contém o site: sem repetir "UOL, UOL Economia"
    assert.match(text, /Opção 1: PUC Paraná, Sites Recomendados\./);
    assert.match(text, /de 1 a 5/);
    assert.doesNotMatch(text, /Fonte/);
});

test('fluxo: opção válida abre em nova aba; fora da faixa avisa; "abrir" repete lista', async () => {
    Object.assign(store, { searchSources: SOURCES });
    ctx.CMD_OK = 'abrir opção 4';
    sent.length = 0; created.length = 0;

    await run('handleNavigateVoiceCommand(CMD_OK)');
    assert.equal(created.at(-1).url, 'https://economia.uol.com.br');
    assert.match(sent.at(-1).ai_response, /Abrindo a opção 4: UOL Economia/);

    created.length = 0; sent.length = 0;
    await run(`handleNavigateVoiceCommand('abrir opção 7')`);
    assert.equal(created.length, 0);
    assert.equal(sent.at(-1).success, false);
    assert.match(sent.at(-1).message, /Só há 5 opções/);

    sent.length = 0;
    await run(`handleNavigateVoiceCommand('abrir')`);
    assert.equal(created.length, 0);
    assert.match(sent.at(-1).ai_response, /Opção 1: PUC Paraná/);

    sent.length = 0;
    await run(`handleNavigateVoiceCommand('abrir uol')`);
    assert.equal(created.at(-1).url, 'https://economia.uol.com.br'); // opção 4, não uol.com.br
});

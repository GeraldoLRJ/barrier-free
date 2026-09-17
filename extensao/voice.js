const micBtn = document.getElementById('micBtn');
const micContainer = document.getElementById('micContainer');
const statusDiv = document.getElementById('status');
const transcriptDiv = document.getElementById('transcript');

// Verificar suporte à Web Speech API
const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

if (!SpeechRecognition) {
  statusDiv.textContent = 'Navegador não suporta reconhecimento de voz';
  statusDiv.className = 'error';
  micBtn.disabled = true;
} else {
  const recognition = new SpeechRecognition();
  recognition.lang = 'pt-BR';
  recognition.interimResults = false;
  recognition.continuous = true;

  let isListening = false;
  let isProcessing = false; // Trava escuta enquanto aguarda resposta da API
  let accumulatedTranscript = ''; // Variável para acumular fala até o usuário dar um comando

  // --- Efeitos sonoros via Web Audio API ---
  const audioCtx = new (window.AudioContext || window.webkitAudioContext)();

  // Som de envio: bip ascendente curto ("ping" para cima)
  function playSendSound() {
    const now = audioCtx.currentTime;
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.connect(gain);
    gain.connect(audioCtx.destination);

    osc.type = 'sine';
    osc.frequency.setValueAtTime(520, now);
    osc.frequency.linearRampToValueAtTime(880, now + 0.12);

    gain.gain.setValueAtTime(0.25, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.25);

    osc.start(now);
    osc.stop(now + 0.25);
  }

  // Som de recebimento: acorde descendente suave ("notificação")
  function playReceiveSound() {
    const now = audioCtx.currentTime;
    const notes = [784, 659, 523]; // Sol5 → Mi5 → Dó5
    const noteDuration = 0.12;

    notes.forEach((freq, i) => {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);

      osc.type = 'sine';
      osc.frequency.setValueAtTime(freq, now + i * noteDuration);

      gain.gain.setValueAtTime(0, now + i * noteDuration);
      gain.gain.linearRampToValueAtTime(0.22, now + i * noteDuration + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, now + i * noteDuration + noteDuration + 0.08);

      osc.start(now + i * noteDuration);
      osc.stop(now + i * noteDuration + noteDuration + 0.1);
    });
  }

  function startListening() {
    try {
      recognition.start();
      isListening = true;
      micBtn.classList.add('listening');
      statusDiv.textContent = 'Ouvindo...';
      statusDiv.className = 'listening';
    } catch (e) {
      // Já está ouvindo, ignorar erro
      console.warn('Recognition já iniciado:', e.message);
    }
  }

  function stopListening() {
    recognition.stop();
    isListening = false;
    micBtn.classList.remove('listening');
    statusDiv.textContent = 'Clique para iniciar';
    statusDiv.className = 'ready';
  }

  micBtn.addEventListener('click', () => {
    if (isListening) {
      stopListening();
    } else {
      startListening();
    }
  });

  // Lista de palavras-chave que disparam comandos reais
  const COMMAND_KEYWORDS = [
    'resumir', 'resumo',
    'orientar', 'ajudar', 'orientação', 'ajuda',
    'buscar', 'pesquisar', 'procurar',
    'abrir', 'navegar',
  ];

  function isValidCommand(text) {
    return COMMAND_KEYWORDS.some(keyword => text.includes(keyword));
  }

  recognition.onresult = (event) => {
    // Ignorar qualquer fala se estiver processando um comando anterior
    if (isProcessing) return;

    // Pegar o último resultado reconhecido
    const lastResult = event.results[event.results.length - 1];

    if (lastResult.isFinal) {
      const transcript = lastResult[0].transcript.trim();
      if (!transcript) return;

      // Acumula o que o usuário disse para que o contexto antes do comando não se perca
      accumulatedTranscript = accumulatedTranscript ? accumulatedTranscript + ' ' + transcript : transcript;
      const lowerCaseAccumulated = accumulatedTranscript.toLowerCase();

      // Exibir apenas a nova frase no transcript
      const entry = document.createElement('div');
      entry.textContent = `» ${transcript}`;
      transcriptDiv.appendChild(entry);
      transcriptDiv.scrollTop = transcriptDiv.scrollHeight;

      // Verificar se a frase acumulada possui uma palavra-chave
      if (!isValidCommand(lowerCaseAccumulated)) {
        // Não encontrou palavra-chave, mas o texto foi acumulado
        return;
      }

      // Comando válido — pausar reconhecimento até receber resposta
      isProcessing = true;
      recognition.stop();

      statusDiv.textContent = 'Processando comando...';
      statusDiv.className = 'processing';
      micBtn.classList.remove('listening');
      micBtn.classList.add('processing');

      // 🔊 Efeito sonoro de envio
      playSendSound();

      // Enviar o comando inteiro acumulado para o background.js
      chrome.runtime.sendMessage({
        action: 'voiceCommand',
        command: lowerCaseAccumulated
      });

      // Limpar o acumulador para as próximas falas
      accumulatedTranscript = '';
    }
  };

  recognition.onerror = (event) => {
    console.error('Erro no reconhecimento de voz:', event.error);

    if (event.error === 'not-allowed') {
      statusDiv.textContent = 'Permissão de microfone negada';
      statusDiv.className = 'error';
      isListening = false;
      micBtn.classList.remove('listening');
    } else if (event.error === 'no-speech') {
      // Silêncio detectado — não é erro real, apenas continuar
      statusDiv.textContent = 'Nenhuma fala detectada. Ouvindo...';
      statusDiv.className = 'listening';
    } else {
      statusDiv.textContent = `Erro: ${event.error}`;
      statusDiv.className = 'error';
    }
  };

  recognition.onend = () => {
    // Não reiniciar se estiver aguardando resposta da API
    if (isProcessing) return;

    // Reiniciar automaticamente se ainda estiver no modo de escuta
    if (isListening) {
      try {
        recognition.start();
      } catch (e) {
        console.warn('Falha ao reiniciar reconhecimento:', e.message);
      }
    }
  };

  // --- Text-to-Speech (TTS) para respostas da IA ---
  let availableVoices = [];
  function loadVoices() {
    availableVoices = window.speechSynthesis.getVoices();
  }
  
  if (window.speechSynthesis) {
    loadVoices();
    if (window.speechSynthesis.onvoiceschanged !== undefined) {
      window.speechSynthesis.onvoiceschanged = loadVoices;
    }
  }

  function speakResponse(text) {
    // Reconhecimento já está parado (isProcessing === true)

    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = 'pt-BR';
    utterance.rate = 1.0; // Voltei para 1.0 pois costuma soar mais natural do que 0.95 com vozes boas
    utterance.pitch = 1;

    // Selecionar uma voz mais fluida/natural, se disponível
    if (availableVoices.length > 0) {
      const ptBrVoices = availableVoices.filter(v => v.lang === 'pt-BR' || v.lang === 'pt_BR' || v.lang === 'pt-br');
      
      // Priorizar vozes de melhor qualidade (Google, Online, Premium, Natural)
      let bestVoice = ptBrVoices.find(v => v.name.includes('Google') || v.name.includes('Online') || v.name.includes('Premium') || v.name.includes('Natural'));
      
      // Se não achar as premium, pega qualquer uma em pt-BR (pode ser a da Microsoft ou padrão)
      if (!bestVoice && ptBrVoices.length > 0) {
        bestVoice = ptBrVoices[0];
      }

      if (bestVoice) {
        utterance.voice = bestVoice;
      }
    }

    utterance.onend = () => {
      // TTS terminou — desbloquear e retomar escuta
      setTimeout(() => {
        resumeListeningAfterProcessing();
      }, 500);
    };

    utterance.onerror = () => {
      resumeListeningAfterProcessing();
    };

    window.speechSynthesis.speak(utterance);
  }

  // Retomar escuta após processamento completo
  function resumeListeningAfterProcessing() {
    isProcessing = false;
    micBtn.classList.remove('processing');
    if (isListening) {
      startListening();
    }
  }

  // Escutar resultados do processamento vindos do background.js
  chrome.runtime.onMessage.addListener((request) => {
    if (request.action === 'processResult') {
      // 🔊 Efeito sonoro de resposta recebida
      playReceiveSound();

      const entry = document.createElement('div');

      if (request.success) {
        entry.style.color = '#81C784';

        if (request.ai_response) {
          // Resposta da IA — exibir e ler em voz alta
          entry.textContent = request.ai_response;
          statusDiv.textContent = 'Lendo resposta...';
          statusDiv.className = 'processing';

          let spokenText = request.ai_response;
          if (request.search_sources && request.search_sources.length > 0) {
            const count = request.search_sources.length;
            const plural = count > 1 ? 's' : '';
            spokenText += `. Encontrei ${count} fonte${plural} relacionada${plural}. Diga "abrir" para navegar até a primeira fonte.`;

            // Exibir fontes no transcript para referência
            const sourcesEntry = document.createElement('div');
            sourcesEntry.style.color = '#64B5F6';
            sourcesEntry.textContent = '🔗 Fontes: ' + request.search_sources.map(s => s.title).join(', ');
            transcriptDiv.appendChild(sourcesEntry);
          }

          // speakResponse já cuida de retomar a escuta ao terminar de falar
          speakResponse(spokenText);
        } else {
          entry.textContent = `✅ ${request.message}`;
          // Sem TTS — retomar escuta imediatamente
          resumeListeningAfterProcessing();
        }
      } else {
        entry.style.color = '#ef5350';
        entry.textContent = `❌ ${request.message}`;
        // Ler erro em voz alta também para acessibilidade
        speakResponse(request.message);
      }

      transcriptDiv.appendChild(entry);
      transcriptDiv.scrollTop = transcriptDiv.scrollHeight;
    }
  });

  // Iniciar automaticamente ao abrir a janela
  startListening();
}

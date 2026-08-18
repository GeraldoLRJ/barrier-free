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

  recognition.onresult = (event) => {
    // Pegar o último resultado reconhecido
    const lastResult = event.results[event.results.length - 1];

    if (lastResult.isFinal) {
      const command = lastResult[0].transcript.trim().toLowerCase();

      // Exibir o texto reconhecido
      const entry = document.createElement('div');
      entry.textContent = `» ${command}`;
      transcriptDiv.appendChild(entry);
      transcriptDiv.scrollTop = transcriptDiv.scrollHeight;

      statusDiv.textContent = 'Processando comando...';
      statusDiv.className = 'processing';

      // Enviar o comando para o background.js
      chrome.runtime.sendMessage({
        action: 'voiceCommand',
        command: command
      });

      // Voltar ao estado de escuta após um breve delay
      setTimeout(() => {
        if (isListening) {
          statusDiv.textContent = 'Ouvindo...';
          statusDiv.className = 'listening';
        }
      }, 1000);
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
  function speakResponse(text) {
    // Pausar reconhecimento enquanto fala para evitar capturar a própria voz
    const wasListening = isListening;
    if (wasListening) {
      recognition.stop();
    }

    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = 'pt-BR';
    utterance.rate = 0.95;
    utterance.pitch = 1;

    utterance.onend = () => {
      // Retomar escuta após terminar de falar
      if (wasListening) {
        setTimeout(() => {
          startListening();
        }, 500);
      }
    };

    utterance.onerror = () => {
      if (wasListening) {
        startListening();
      }
    };

    window.speechSynthesis.speak(utterance);
  }

  // Escutar resultados do processamento vindos do background.js
  chrome.runtime.onMessage.addListener((request) => {
    if (request.action === 'processResult') {
      const entry = document.createElement('div');

      if (request.success) {
        entry.style.color = '#81C784';

        if (request.ai_response) {
          // Resposta da IA — exibir e ler em voz alta
          entry.textContent = request.ai_response;
          statusDiv.textContent = 'Lendo resposta...';
          statusDiv.className = 'processing';
          speakResponse(request.ai_response);
        } else {
          entry.textContent = `✅ ${request.message}`;
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

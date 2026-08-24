// --- Controle do Analisador IA ---
document.getElementById('aiAnalyzeBtn').addEventListener('click', () => {
  const statusDiv = document.getElementById('status');
  statusDiv.textContent = "Enviando para resumo da IA...";
  
  chrome.runtime.sendMessage({
    action: 'summarizeDom'
  });
});

// --- Controle do Comando de Voz ---
const voiceBtn = document.getElementById('voiceBtn');
let isVoiceEnabled = false;

// Restaurar estado salvo ao abrir o popup
chrome.storage.local.get('isVoiceEnabled', (data) => {
  isVoiceEnabled = data.isVoiceEnabled || false;
  updateVoiceButton();
});

function updateVoiceButton() {
  if (isVoiceEnabled) {
    voiceBtn.textContent = '🔴 Desativar Voz';
    voiceBtn.classList.add('active');
  } else {
    voiceBtn.textContent = '🎤 Ativar Voz';
    voiceBtn.classList.remove('active');
  }
}

voiceBtn.addEventListener('click', () => {
  isVoiceEnabled = !isVoiceEnabled;
  chrome.storage.local.set({ isVoiceEnabled });
  updateVoiceButton();

  // Envia para o background abrir/fechar a janela de voz
  chrome.runtime.sendMessage({
    action: 'toggleVoice',
    enabled: isVoiceEnabled
  });
});

// Escuta respostas do Service Worker
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === "processResult") {
    const statusDiv = document.getElementById('status');
    if (request.success) {
      statusDiv.style.color = "green";
      statusDiv.textContent = "Concluído! " + request.message;
    } else {
      statusDiv.style.color = "red";
      statusDiv.textContent = "Falha: " + request.message;
    }
  }

  // Atualiza o botão se a janela de voz foi fechada externamente
  if (request.action === "voiceStatus" && request.status === "ended") {
    isVoiceEnabled = false;
    updateVoiceButton();
  }
});

// Extrai o conteúdo do body (sem scripts/styles) e a URL
const bodyClone = document.body.cloneNode(true);
bodyClone.querySelectorAll('script, style, noscript, iframe').forEach(el => el.remove());

const domData = {
  html_content: bodyClone.innerHTML,
  url: window.location.href
};

// Envia os dados para o background.js (Service Worker) para realizar a requisição externa
chrome.runtime.sendMessage({ action: "sendDom", data: domData });

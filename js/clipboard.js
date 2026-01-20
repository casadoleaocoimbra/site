/**
 * Clipboard functionality for Casa do Leão donation website
 * Handles copy-to-clipboard operations for bank account details
 */

// Copy to clipboard functionality
function copyToClipboard(text) {
  if (navigator.clipboard && window.isSecureContext) {
    // Use modern clipboard API
    navigator.clipboard.writeText(text).then(function() {
      showCopyNotification('Copiado para a área de transferência!');
    }).catch(function(err) {
      console.error('Erro ao copiar: ', err);
      fallbackCopyTextToClipboard(text);
    });
  } else {
    // Fallback for older browsers or non-HTTPS
    fallbackCopyTextToClipboard(text);
  }
}

// Fallback copy method
function fallbackCopyTextToClipboard(text) {
  var textArea = document.createElement("textarea");
  textArea.value = text;
  textArea.style.top = "0";
  textArea.style.left = "0";
  textArea.style.position = "fixed";
  textArea.style.opacity = "0";
  
  document.body.appendChild(textArea);
  textArea.focus();
  textArea.select();
  
  try {
    var successful = document.execCommand('copy');
    if (successful) {
      showCopyNotification('Copiado para a área de transferência!');
    } else {
      showCopyNotification('Erro ao copiar. Tente selecionar e copiar manualmente.');
    }
  } catch (err) {
    console.error('Fallback: Erro ao copiar', err);
    showCopyNotification('Erro ao copiar. Tente selecionar e copiar manualmente.');
  }
  
  document.body.removeChild(textArea);
}

// Show copy notification
function showCopyNotification(message) {
  // Remove existing notification if any
  var existingNotification = document.getElementById('copy-notification');
  if (existingNotification) {
    existingNotification.remove();
  }

  // Create notification element
  var notification = document.createElement('div');
  notification.id = 'copy-notification';
  notification.textContent = message;
  notification.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    background: #4CAF50;
    color: white;
    padding: 12px 20px;
    border-radius: 4px;
    font-family: Arial, sans-serif;
    font-size: 14px;
    z-index: 10000;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    animation: slideIn 0.3s ease-out;
  `;

  // Add CSS animation
  if (!document.getElementById('copy-notification-style')) {
    var style = document.createElement('style');
    style.id = 'copy-notification-style';
    style.textContent = `
      @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
      }
      @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
      }
    `;
    document.head.appendChild(style);
  }

  document.body.appendChild(notification);

  // Auto remove notification after 3 seconds
  setTimeout(function() {
    notification.style.animation = 'slideOut 0.3s ease-in';
    setTimeout(function() {
      if (notification.parentNode) {
        notification.parentNode.removeChild(notification);
      }
    }, 300);
  }, 3000);
}

// Initialize clipboard functionality when page loads
document.addEventListener('DOMContentLoaded', function() {
  // Add click event listeners to all clipboard buttons
  var clipboardButtons = document.querySelectorAll('.btn-clipboard');
  
  clipboardButtons.forEach(function(button) {
    button.addEventListener('click', function(e) {
      e.preventDefault();
      
      // Find the text to copy from the parent element
      var parentSpan = button.parentElement;
      var textToCopy = '';
      
      // Extract text based on the parent content
      if (parentSpan.querySelector('strong')) {
        // Get text after the <strong> tag
        var strongElement = parentSpan.querySelector('strong');
        var textAfterStrong = strongElement.nextSibling;
        if (textAfterStrong && textAfterStrong.textContent) {
          textToCopy = textAfterStrong.textContent.trim();
          // Remove any extra characters like colons
          textToCopy = textToCopy.replace(/^:\s*/, '');
        }
      } else {
        // For elements without <strong> tag (like organization name)
        var buttonIndex = Array.from(parentSpan.childNodes).indexOf(button);
        var textNodes = Array.from(parentSpan.childNodes).slice(0, buttonIndex);
        textToCopy = textNodes.map(node => node.textContent || '').join('').trim();
      }
      
      if (textToCopy) {
        copyToClipboard(textToCopy);
      }
    });
    
    // Add hover effect
    button.addEventListener('mouseenter', function() {
      this.style.opacity = '0.7';
    });
    
    button.addEventListener('mouseleave', function() {
      this.style.opacity = '1';
    });
  });
});
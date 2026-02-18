// Firebase Configuration and Authentication
const firebaseConfig = {
  apiKey: "AIzaSyC2Ud-lC4jnCQIwA5QyufVczfiovGHZRXI",
  authDomain: "quebot-2d931.firebaseapp.com",
  projectId: "quebot-2d931",
  storageBucket: "quebot-2d931.firebasestorage.app",
  messagingSenderId: "677829275031",
  appId: "1:677829275031:web:24d80af520163d6344e8d2",
  measurementId: "G-TYL4M9LLQT"
};

// User levels
const USER_LEVEL = {
  ANONYMOUS: 'anonymous',
  LIGHT: 'light',
  FULL: 'full'
};

class QueBotAuth {
  constructor() {
    this.app = null;
    this.auth = null;
    this.db = null;
    this.currentUser = null;
    this.userProfile = null;
    this.messageCount = 0;
    this.hasAskedForRegistration = false;
    this.initialized = false;
  }

  // Detect if running on mobile
  isMobile() {
    return window.innerWidth <= 768 || /Android|iPhone|iPad|iPod|Opera Mini|IEMobile/i.test(navigator.userAgent);
  }

  async init() {
    try {
      // Initialize Firebase
      this.app = firebase.initializeApp(firebaseConfig);
      this.auth = firebase.auth();
      this.db = firebase.firestore();
      
      // Check for redirect result first (mobile Google login)
      try {
        const result = await this.auth.getRedirectResult();
        if (result && result.user && !result.user.isAnonymous) {
          this.currentUser = result.user;
          await this.saveUserProfile({
            level: USER_LEVEL.FULL,
            name: result.user.displayName,
            email: result.user.email,
            photoURL: result.user.photoURL,
            provider: 'google',
            updatedAt: new Date().toISOString()
          });
          this.updateUI();
          console.log('Google redirect login successful');
        }
      } catch (redirectError) {
        console.log('No redirect result:', redirectError.message);
      }
      
      // Listen for auth state changes
      this.auth.onAuthStateChanged(async (user) => {
        if (user) {
          this.currentUser = user;
          await this.loadUserProfile();
          this.updateUI();
        } else {
          // Sign in anonymously by default
          await this.signInAnonymously();
        }
      });
      
      this.initialized = true;
      console.log('Firebase initialized successfully');
    } catch (error) {
      console.error('Firebase init error:', error);
      this.initialized = false;
    }
  }

  async signInAnonymously() {
    try {
      const result = await this.auth.signInAnonymously();
      this.currentUser = result.user;
      this.userProfile = {
        level: USER_LEVEL.ANONYMOUS,
        createdAt: new Date().toISOString()
      };
      console.log('Signed in anonymously');
    } catch (error) {
      console.error('Anonymous sign in error:', error);
    }
  }

  async signInWithGoogle() {
    try {
      const provider = new firebase.auth.GoogleAuthProvider();
      
      // On mobile, use redirect (popup doesn't work well)
      if (this.isMobile()) {
        await this.auth.signInWithRedirect(provider);
        // Page will redirect, so no code after this runs
        return { success: true, redirecting: true };
      }
      
      // On desktop, use popup
      const result = await this.auth.signInWithPopup(provider);
      this.currentUser = result.user;
      
      await this.saveUserProfile({
        level: USER_LEVEL.FULL,
        name: result.user.displayName,
        email: result.user.email,
        photoURL: result.user.photoURL,
        provider: 'google',
        updatedAt: new Date().toISOString()
      });
      
      this.updateUI();
      return { success: true, user: result.user };
    } catch (error) {
      console.error('Google sign in error:', error);
      return { success: false, error: error.message };
    }
  }

  async registerLight(userData) {
    try {
      const profile = {
        level: USER_LEVEL.LIGHT,
        name: userData.name || null,
        email: userData.email || null,
        phone: userData.phone || null,
        updatedAt: new Date().toISOString()
      };
      
      await this.saveUserProfile(profile);
      this.updateUI();
      return { success: true };
    } catch (error) {
      console.error('Light registration error:', error);
      return { success: false, error: error.message };
    }
  }

  async loadUserProfile() {
    if (!this.currentUser || !this.db) return;
    
    try {
      const doc = await this.db.collection('users').doc(this.currentUser.uid).get();
      if (doc.exists) {
        this.userProfile = doc.data();
      } else {
        this.userProfile = {
          level: this.currentUser.isAnonymous ? USER_LEVEL.ANONYMOUS : USER_LEVEL.FULL,
          createdAt: new Date().toISOString()
        };
        // If user signed in with Google but no profile yet, create one
        if (!this.currentUser.isAnonymous && this.currentUser.displayName) {
          this.userProfile.name = this.currentUser.displayName;
          this.userProfile.email = this.currentUser.email;
          this.userProfile.photoURL = this.currentUser.photoURL;
          this.userProfile.level = USER_LEVEL.FULL;
          this.userProfile.provider = 'google';
          await this.saveUserProfile(this.userProfile);
        }
      }
    } catch (error) {
      console.error('Load profile error:', error);
      this.userProfile = { level: USER_LEVEL.ANONYMOUS };
    }
  }

  async saveUserProfile(profile) {
    if (!this.currentUser || !this.db) return;
    
    try {
      this.userProfile = { ...this.userProfile, ...profile };
      await this.db.collection('users').doc(this.currentUser.uid).set(this.userProfile, { merge: true });
      console.log('Profile saved:', this.userProfile);
    } catch (error) {
      console.error('Save profile error:', error);
    }
  }

  async saveConversation(conversationId, messages, title) {
    if (!this.currentUser || !this.db || this.userProfile?.level === USER_LEVEL.ANONYMOUS) {
      return false;
    }
    
    try {
      await this.db.collection('users').doc(this.currentUser.uid)
        .collection('conversations').doc(conversationId).set({
          title: title,
          messages: messages,
          updatedAt: new Date().toISOString()
        }, { merge: true });
      return true;
    } catch (error) {
      console.error('Save conversation error:', error);
      return false;
    }
  }

  async loadConversations() {
    if (!this.currentUser || !this.db || this.userProfile?.level === USER_LEVEL.ANONYMOUS) {
      return null;
    }
    
    try {
      const snapshot = await this.db.collection('users').doc(this.currentUser.uid)
        .collection('conversations').orderBy('updatedAt', 'desc').get();
      
      const conversations = {};
      snapshot.forEach(doc => {
        conversations[doc.id] = doc.data();
      });
      return conversations;
    } catch (error) {
      console.error('Load conversations error:', error);
      return null;
    }
  }

  async signOut() {
    try {
      await this.auth.signOut();
      this.currentUser = null;
      this.userProfile = null;
      this.updateUI();
    } catch (error) {
      console.error('Sign out error:', error);
    }
  }

  shouldAskForRegistration() {
    if (this.hasAskedForRegistration) return false;
    if (this.userProfile?.level !== USER_LEVEL.ANONYMOUS) return false;
    if (this.messageCount < 4) return false;
    return Math.random() > 0.5;
  }

  incrementMessageCount() {
    this.messageCount++;
  }

  markAskedForRegistration() {
    this.hasAskedForRegistration = true;
  }

  getUserLevel() {
    return this.userProfile?.level || USER_LEVEL.ANONYMOUS;
  }

  getUserName() {
    return this.userProfile?.name || 'Usuario';
  }

  getUserContext() {
    if (!this.userProfile) return '';
    
    let context = '';
    if (this.userProfile.name) {
      context += `El usuario se llama ${this.userProfile.name}. `;
    }
    if (this.userProfile.level === USER_LEVEL.ANONYMOUS) {
      context += 'El usuario es anónimo y aún no ha compartido información personal. ';
    } else if (this.userProfile.level === USER_LEVEL.LIGHT) {
      context += 'El usuario tiene un registro ligero. ';
    } else if (this.userProfile.level === USER_LEVEL.FULL) {
      context += 'El usuario está autenticado completamente con Google. ';
    }
    return context;
  }

  updateUI() {
    const userAvatar = document.querySelector('.user-avatar');
    const userName = document.querySelector('.user-name');
    const userInfo = document.querySelector('.user-info');
    
    if (!userAvatar || !userName) return;
    
    if (this.userProfile?.level === USER_LEVEL.FULL && this.userProfile.photoURL) {
      userAvatar.innerHTML = `<img src="${this.userProfile.photoURL}" alt="Avatar" style="width:100%;height:100%;border-radius:50%;">`;
      userName.textContent = this.userProfile.name || 'Usuario';
    } else if (this.userProfile?.level === USER_LEVEL.LIGHT && this.userProfile.name) {
      userAvatar.textContent = this.userProfile.name.charAt(0).toUpperCase();
      userName.textContent = this.userProfile.name;
    } else {
      userAvatar.textContent = '?';
      userName.textContent = 'Invitado';
    }
    
    // Ensure sidebar stays collapsed on mobile after login
    if (this.isMobile()) {
      const sidebar = document.querySelector('.sidebar');
      const overlay = document.getElementById('sidebarOverlay');
      if (sidebar) sidebar.classList.add('collapsed');
      if (overlay) overlay.classList.remove('active');
    }
    
    // Make user info clickable
    if (userInfo && !userInfo.hasClickListener) {
      userInfo.style.cursor = 'pointer';
      userInfo.addEventListener('click', () => this.showAuthModal());
      userInfo.hasClickListener = true;
    }
  }

  showAuthModal() {
    let modal = document.getElementById('authModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'authModal';
      modal.className = 'auth-modal';
      document.body.appendChild(modal);
    }
    
    const level = this.getUserLevel();
    let content = '';
    
    if (level === USER_LEVEL.FULL) {
      content = `
        <div class="auth-modal-content">
          <h3>\uD83D\uDC4B Hola, ${this.userProfile.name}!</h3>
          <p>Conectado con Google</p>
          <p class="auth-email">${this.userProfile.email}</p>
          <div class="auth-buttons">
            <button class="auth-btn secondary" onclick="queBotAuth.signOut(); queBotAuth.hideAuthModal();">Cerrar sesión</button>
            <button class="auth-btn secondary" onclick="queBotAuth.hideAuthModal();">Cerrar</button>
          </div>
        </div>
      `;
    } else if (level === USER_LEVEL.LIGHT) {
      content = `
        <div class="auth-modal-content">
          <h3>\uD83D\uDC4B Hola, ${this.userProfile.name || 'amigo'}!</h3>
          <p>Tienes un registro ligero</p>
          <div class="auth-buttons">
            <button class="auth-btn primary" onclick="queBotAuth.signInWithGoogle().then(() => queBotAuth.hideAuthModal());">Conectar con Google</button>
            <button class="auth-btn secondary" onclick="queBotAuth.hideAuthModal();">Cerrar</button>
          </div>
        </div>
      `;
    } else {
      content = `
        <div class="auth-modal-content">
          <h3>\uD83E\uDD16 ¡Bienvenido a QueBot!</h3>
          <p>Conéctate para guardar tus conversaciones en la nube</p>
          <div class="auth-buttons">
            <button class="auth-btn google" onclick="queBotAuth.signInWithGoogle().then(r => { if(!r.redirecting) queBotAuth.hideAuthModal(); });">
              <svg viewBox="0 0 24 24" width="20" height="20"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
              Continuar con Google
            </button>
            <button class="auth-btn secondary" onclick="queBotAuth.hideAuthModal();">Seguir como invitado</button>
          </div>
          <p class="auth-note">Tus conversaciones actuales se guardan solo en este navegador</p>
        </div>
      `;
    }
    
    modal.innerHTML = content;
    modal.style.display = 'flex';
    
    modal.onclick = (e) => {
      if (e.target === modal) this.hideAuthModal();
    };
  }

  hideAuthModal() {
    const modal = document.getElementById('authModal');
    if (modal) modal.style.display = 'none';
  }

  async processRegistrationFromChat(message) {
    const lowerMsg = message.toLowerCase();
    
    const namePatterns = [
      /me llamo ([\w\s]+)/i,
      /mi nombre es ([\w\s]+)/i,
      /soy ([\w\s]+)/i
    ];
    
    const emailPatterns = [
      /([\w.-]+@[\w.-]+\.[\w]+)/i
    ];
    
    const phonePatterns = [
      /([+]?[0-9]{8,12})/
    ];
    
    let updates = {};
    
    for (const pattern of namePatterns) {
      const match = message.match(pattern);
      if (match) {
        updates.name = match[1].trim().split(' ').slice(0, 3).join(' ');
        break;
      }
    }
    
    for (const pattern of emailPatterns) {
      const match = message.match(pattern);
      if (match) {
        updates.email = match[1];
        break;
      }
    }
    
    for (const pattern of phonePatterns) {
      const match = message.match(pattern);
      if (match) {
        updates.phone = match[1];
        break;
      }
    }
    
    if (Object.keys(updates).length > 0) {
      updates.level = USER_LEVEL.LIGHT;
      await this.registerLight(updates);
      return true;
    }
    
    return false;
  }
}

// Global instance
const queBotAuth = new QueBotAuth();

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  queBotAuth.init();
});

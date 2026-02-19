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
    this.searchProfile = null;
    this.messageCount = 0;
    this.hasAskedForRegistration = false;
    this.initialized = false;
    // Map localStorage chatId -> Firestore caseId
    this.caseMap = {};
  }

  async init() {
    try {
      this.app = firebase.initializeApp(firebaseConfig);
      this.auth = firebase.auth();
      this.db = firebase.firestore();
      
      // Initialize QueBotDatabase with Firestore
      if (typeof queBotDB !== 'undefined') {
        queBotDB.init(this.db);
      }
      
      this.auth.onAuthStateChanged(async (user) => {
        if (user) {
          this.currentUser = user;
          await this.loadUserProfile();
        await this.loadSearchProfile();
          this.updateUI();
        } else {
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
      // Save anonymous user to Firestore
      if (typeof queBotDB !== 'undefined' && queBotDB.isReady()) {
        await queBotDB.saveUser(this.currentUser.uid, {
          level: USER_LEVEL.ANONYMOUS
        });
      }
      console.log('Signed in anonymously');
    } catch (error) {
      console.error('Anonymous sign in error:', error);
    }
  }

  async signInWithGoogle() {
    try {
      const provider = new firebase.auth.GoogleAuthProvider();
      const result = await this.auth.signInWithPopup(provider);
      this.currentUser = result.user;
      
      // Close modal immediately after successful login
      this.hideAuthModal();
      
      // Save full user profile to Firestore via queBotDB
      const profileData = {
        level: USER_LEVEL.FULL,
        name: result.user.displayName,
        email: result.user.email,
        photoURL: result.user.photoURL,
        provider: 'google'
      };

      if (typeof queBotDB !== 'undefined' && queBotDB.isReady()) {
        await queBotDB.saveUser(this.currentUser.uid, profileData);
      }

      // Also save to legacy profile
      await this.saveUserProfile(profileData);
      
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
      
      if (typeof queBotDB !== 'undefined' && queBotDB.isReady() && this.currentUser) {
        await queBotDB.saveUser(this.currentUser.uid, profile);
      }
      
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
      // Try new users collection first
      if (typeof queBotDB !== 'undefined' && queBotDB.isReady()) {
        const userData = await queBotDB.getUser(this.currentUser.uid);
        if (userData) {
          this.userProfile = userData;
          // Update last_seen
          await queBotDB.saveUser(this.currentUser.uid, { level: userData.level });
          return;
        }
      }

      // Fallback to legacy collection
      const doc = await this.db.collection('users').doc(this.currentUser.uid).get();
      if (doc.exists) {
        this.userProfile = doc.data();
      } else {
        this.userProfile = {
          level: this.currentUser.isAnonymous ? USER_LEVEL.ANONYMOUS : USER_LEVEL.FULL,
          createdAt: new Date().toISOString()
        };
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
    } catch (error) {
      console.error('Save profile error:', error);
    }
  }

  // Get or create a Firestore case for a localStorage chat
  async ensureCase(localChatId, title) {
    if (!this.currentUser || !queBotDB || !queBotDB.isReady()) return null;
    if (this.userProfile && this.userProfile.level === USER_LEVEL.ANONYMOUS) return null;

    if (this.caseMap[localChatId]) {
      return this.caseMap[localChatId];
    }

    try {
      const caseId = await queBotDB.createCase(
        this.currentUser.uid,
        'web',
        title || 'Nueva conversación'
      );
      if (caseId) {
        this.caseMap[localChatId] = caseId;
      }
      return caseId;
    } catch (e) {
      console.error('Ensure case error:', e);
      return null;
    }
  }

  // Save a message to the Firestore case
  async saveMessageToCase(localChatId, role, text) {
    const caseId = this.caseMap[localChatId];
    if (!caseId || !queBotDB || !queBotDB.isReady()) return null;
    
    try {
      return await queBotDB.addMessage(caseId, role, text);
    } catch (e) {
      console.error('Save message to case error:', e);
      return null;
    }
  }

  // Log a run with metadata
  async logRun(localChatId, triggerMessageId, metadata) {
    const caseId = this.caseMap[localChatId];
    if (!caseId || !queBotDB || !queBotDB.isReady()) return null;
    
    try {
      const runId = await queBotDB.createRun(caseId, triggerMessageId, metadata);
      // Also log an LLM_CALL event
      if (runId) {
        await queBotDB.logEvent(caseId, runId, 'LLM_CALL', {
          model: metadata.model,
          tokens: (metadata.input_tokens || 0) + (metadata.output_tokens || 0),
          timing_ms: metadata.timing_total
        });
        if (metadata.searched) {
          await queBotDB.logEvent(caseId, runId, 'RAG_SEARCH', {
            timing_ms: metadata.timing_rag
          });
        }
        if (metadata.legal_used) {
          await queBotDB.logEvent(caseId, runId, 'LEGAL_RAG', {});
        }
      }
      return runId;
    } catch (e) {
      console.error('Log run error:', e);
      return null;
    }
  }

  // Save conversation to Firestore (backward compatibility)
  async saveConversation(conversationId, messages, title) {
    if (!this.currentUser || !this.db || this.userProfile?.level === USER_LEVEL.ANONYMOUS) {
      return false;
    }
    
    try {
      // Ensure case exists for this chat
      await this.ensureCase(conversationId, title);
      return true;
    } catch (error) {
      console.error('Save conversation error:', error);
      return false;
    }
  }

  // Load conversations from Firestore
  async loadConversations() {
    if (!this.currentUser || !this.db || this.userProfile?.level === USER_LEVEL.ANONYMOUS) {
      return null;
    }
    
    try {
      if (typeof queBotDB !== 'undefined' && queBotDB.isReady()) {
        return await queBotDB.getCases(this.currentUser.uid);
      }
      return null;
    } catch (error) {
      console.error('Load conversations error:', error);
      return null;
    }
  }

  // Delete conversation
  async deleteConversation(conversationId) {
    const caseId = this.caseMap[conversationId];
    if (caseId && typeof queBotDB !== 'undefined' && queBotDB.isReady()) {
      await queBotDB.deleteCase(caseId);
      delete this.caseMap[conversationId];
    }
  }

  // Get user context string for API

  // === SEARCH PROFILE (Memory System B1) ===
  
  /**
   * Get the user's search profile for sending to backend.
   * Returns cached profile or null if not loaded yet.
   */
  getSearchProfile() {
    return this.searchProfile || null;
  }

  /**
   * Save updated search profile from backend response.
   * Stores in Firestore and local cache.
   */
  async saveSearchProfile(profileData) {
    if (!profileData || !this.currentUser) return;
    
    this.searchProfile = profileData;
    
    try {
      // Save to Firestore under users/{uid}
      if (typeof queBotDB !== 'undefined') {
        await queBotDB.saveUser(this.currentUser.uid, {
          search_profile: profileData
        });
      }
      console.log('Search profile saved:', Object.keys(profileData).length, 'fields');
    } catch (error) {
      console.error('Save search profile error:', error);
    }
  }

  /**
   * Load search profile from Firestore on init.
   */
  async loadSearchProfile() {
    if (!this.currentUser) return;
    
    try {
      if (typeof queBotDB !== 'undefined') {
        const userData = await queBotDB.getUser(this.currentUser.uid);
        if (userData && userData.search_profile) {
          this.searchProfile = userData.search_profile;
          console.log('Search profile loaded:', Object.keys(this.searchProfile).length, 'fields');
        }
      }
    } catch (error) {
      console.error('Load search profile error:', error);
    }
  }

  getUserContext() {
    if (!this.userProfile) return '';
    
    let context = '';
    if (this.userProfile.name) {
      context += `El usuario se llama ${this.userProfile.name}. `;
    }
    if (this.userProfile.level === USER_LEVEL.FULL) {
      context += 'Es un usuario registrado con Google. ';
    }
    return context;
  }

  incrementMessageCount() {
    this.messageCount++;
  }

  shouldAskForRegistration() {
    if (this.hasAskedForRegistration) return false;
    if (this.userProfile && this.userProfile.level !== USER_LEVEL.ANONYMOUS) return false;
    if (this.messageCount < 5) return false;
    
    this.hasAskedForRegistration = true;
    return true;
  }

  processRegistrationFromChat(message) {
    // Not implemented yet
  }

  // UI Methods
  updateUI() {
    const userInfo = document.getElementById('userInfo');
    if (!userInfo) return;

    if (this.currentUser && !this.currentUser.isAnonymous) {
      const name = this.userProfile?.name || this.currentUser.displayName || 'Usuario';
      const photo = this.userProfile?.photoURL || this.currentUser.photoURL;
      
      userInfo.innerHTML = `
        <div class="user-avatar" style="background-image: url('${photo || ''}')">
          ${!photo ? name.charAt(0).toUpperCase() : ''}
        </div>
        <span class="user-name">${name}</span>
      `;
    } else {
      userInfo.innerHTML = `
        <div class="user-avatar guest">👤</div>
        <span class="user-name">Invitado</span>
      `;
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

    modal.innerHTML = `
      <div class="auth-modal-content">
        <div class="auth-modal-header">
          <span class="auth-modal-icon">🤖</span>
          <h2>¡Bienvenido a QueBot!</h2>
          <p>Conéctate para guardar tus conversaciones en la nube</p>
        </div>
        <div class="auth-modal-buttons">
          <button class="auth-btn auth-btn-google" onclick="queBotAuth.signInWithGoogle()">
            <span class="auth-btn-icon">G</span>
            Continuar con Google
          </button>
          <button class="auth-btn auth-btn-guest" onclick="queBotAuth.hideAuthModal()">
            Seguir como invitado
          </button>
        </div>
        <p class="auth-modal-footer">Tus conversaciones actuales se guardan solo en este navegador</p>
      </div>
    `;

    modal.style.display = 'flex';
    
    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        this.hideAuthModal();
      }
    });
  }

  hideAuthModal() {
    const modal = document.getElementById('authModal');
    if (modal) {
      modal.style.display = 'none';
    }
  }
}

// Initialize global auth instance
const queBotAuth = new QueBotAuth();
document.addEventListener('DOMContentLoaded', () => {
  queBotAuth.init();
});

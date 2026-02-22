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


/**
 * Detect current environment from hostname.
 * Tags all Firestore writes so staging/production data doesn't mix.
 */
function getQueBotEnvironment() {
  const host = window.location.hostname;
  if (host.includes('quebot-production')) return 'production';
  if (host.includes('spirited-purpose')) return 'staging';
  if (host.includes('localhost') || host.includes('127.0.0.1')) return 'local';
  if (host.includes('charming-embrace')) return 'lab';
  if (host.includes('lab')) return 'lab';
  return 'unknown';
}

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
  }

  async init() {
    try {
      // Initialize Firebase
      this.app = firebase.initializeApp(firebaseConfig);
      this.auth = firebase.auth();
      this.db = firebase.firestore();
      
      // Ensure login persists across browser sessions
      await this.auth.setPersistence(firebase.auth.Auth.Persistence.LOCAL).catch(e => {
        console.warn('[Auth] setPersistence failed:', e.message);
      });
      
      // Listen for auth state changes
      this.auth.onAuthStateChanged(async (user) => {
        console.log('[Auth] State changed:', user?.uid, user?.email || 'anonymous');
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
      // Fallback to localStorage only
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
      const result = await this.auth.signInWithPopup(provider);
      this.currentUser = result.user;
      
      // Close modal immediately after successful login
      this.hideAuthModal();
      
      // Create/update full user profile
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
        this.searchProfile = doc.data()?.search_profile || null;
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
      this.userProfile = { ...this.userProfile, ...profile, environment: getQueBotEnvironment() };
      await this.db.collection('users').doc(this.currentUser.uid).set(this.userProfile, { merge: true });
      console.log('Profile saved:', this.userProfile);
    } catch (error) {
      console.error('Save profile error:', error);
    }
  }

  // Save conversation to Firestore
  async saveConversation(conversationId, messages, title) {
    if (!this.currentUser || !this.db || this.userProfile?.level === USER_LEVEL.ANONYMOUS) {
      // For anonymous users, use localStorage only
      return false;
    }
    
    try {
      await this.db.collection('users').doc(this.currentUser.uid)
        .collection('conversations').doc(conversationId).set({
          title: title,
          messages: messages,
          updatedAt: new Date().toISOString(),
          environment: getQueBotEnvironment()
        }, { merge: true });
      return true;
    } catch (error) {
      console.error('Save conversation error:', error);
      return false;
    }
  }

  // Load conversations from Firestore
  async loadConversations() {
    if (!this.currentUser || !this.db || this.userProfile?.level === USER_LEVEL.ANONYMOUS) {
      return null; // Use localStorage
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
    this.searchProfile = null;
      this.updateUI();
    } catch (error) {
      console.error('Sign out error:', error);
    }
  }

  // Check if we should ask for registration (after 4-5 helpful messages)
  shouldAskForRegistration() {
    if (this.hasAskedForRegistration) return false;
    if (this.userProfile?.level !== USER_LEVEL.ANONYMOUS) return false;
    if (this.messageCount < 4) return false;
    
    // 50% chance after 4 messages
    return Math.random() > 0.5;
  }

  incrementMessageCount() {
    this.messageCount++;
  }

  markAskedForRegistration() {
    this.askedForRegistration = true;
  }

  // === Search Profile Methods ===
  
  getSearchProfile() {
    return this.searchProfile || null;
  }

  async saveSearchProfile(profileData) {
    if (!this.currentUser || !this.db || !profileData) return;
    try {
      // Profile v2: backend already does weighted merge — save directly
      const isV2 = profileData.profile_version === 2;
      
      let merged;
      if (isV2) {
        // v2: Backend handled all merge logic with weights/confidence
        // Just save what backend returned
        merged = profileData;
      } else {
        // v1 legacy: simple merge for backward compatibility
        const existing = this.searchProfile || {};
        merged = { ...existing };
        
        for (const [key, value] of Object.entries(profileData)) {
          if (Array.isArray(value) && Array.isArray(existing[key])) {
            // For v1 flat arrays, deduplicate
            const existingNames = existing[key].map(i => 
              typeof i === 'string' ? i.toLowerCase() : (i.name || '').toLowerCase()
            );
            const newItems = value.filter(v => {
              const name = typeof v === 'string' ? v.toLowerCase() : (v.name || '').toLowerCase();
              return !existingNames.includes(name);
            });
            merged[key] = [...existing[key], ...newItems];
          } else if (value !== null && value !== undefined) {
            merged[key] = value;
          }
        }
      }
      
      merged.updated_at = new Date().toISOString();
      
      await this.db.collection('users').doc(this.currentUser.uid).set(
        { search_profile: merged }, 
        { merge: true }
      );
      
      this.searchProfile = merged;
      console.log('Search profile saved (v' + (isV2 ? '2' : '1') + '):', Object.keys(merged).length, 'fields');
    } catch (error) {
      console.error('Save search profile error:', error);
    }
  }


  // === Case Management Methods ===
  
  // Map of caseId -> Firestore doc created
  get caseMap() {
    if (!this._caseMap) this._caseMap = {};
    return this._caseMap;
  }

  async ensureCase(caseId, title) {
    if (!this.currentUser || !this.db || !caseId) return;
    if (this.caseMap[caseId]) return; // Already created
    try {
      await this.db.collection('cases').doc(caseId).set({
        case_id: caseId,
        user_id: this.currentUser.uid,
        tenant_id: 'quebot',
        status: 'open',
        channel: 'web',
        created_at: firebase.firestore.FieldValue.serverTimestamp(),
        title: title || 'Nueva Misión',
        environment: getQueBotEnvironment()
      }, { merge: true });
      this.caseMap[caseId] = true;
    } catch (error) {
      console.error('ensureCase error:', error);
    }
  }

  async saveMessageToCase(caseId, role, text) {
    if (!this.currentUser || !this.db || !caseId) return null;
    try {
      const msgId = 'msg_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);
      await this.db.collection('messages').doc(msgId).set({
        message_id: msgId,
        case_id: caseId,
        role: role,
        text: text,
        created_at: firebase.firestore.FieldValue.serverTimestamp(),
        environment: getQueBotEnvironment()
      });
      return msgId;
    } catch (error) {
      console.error('saveMessageToCase error:', error);
      return null;
    }
  }

  async logRun(caseId, messageId, metadata) {
    if (!this.currentUser || !this.db) return null;
    try {
      const runId = 'run_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);
      await this.db.collection('runs').doc(runId).set({
        run_id: runId,
        case_id: caseId || '',
        trigger_message_id: messageId || '',
        user_id: this.currentUser.uid || '',
        provider: metadata.fallback_used ? 'openai' : 'anthropic',
        models: {
          reasoner_model: metadata.model || '',
          verifier_model: metadata.verifier_verdict ? 'claude-3-haiku-20240307' : '-',
          ui_planner_model: '-'
        },
        timing_ms: {
          total: metadata.timing_total || 0,
          rag: metadata.timing_rag || 0,
          llm: metadata.timing_llm || 0,
          verify: metadata.timing_verifier || 0,
          profile: metadata.timing_profile || 0
        },
        token_usage: {
          input_tokens: metadata.input_tokens || 0,
          output_tokens: metadata.output_tokens || 0
        },
        cost_estimate: metadata.cost_estimate || 0,
        result_flags: {
          searched: metadata.searched || false,
          legal_used: metadata.legal_used || false,
          fallback_used: metadata.fallback_used || false,
          fabricated_urls_caught: metadata.fabricated_urls_caught || 0,
          verifier_verdict: metadata.verifier_verdict || null,
          verifier_confidence: metadata.verifier_confidence || null,
          search_valid_listings: metadata.search_valid_listings || 0,
          search_insufficient: metadata.search_insufficient || false
        },
        created_at: firebase.firestore.FieldValue.serverTimestamp(),
        environment: getQueBotEnvironment()
      });
      return runId;
    } catch (error) {
      console.error('logRun error:', error);
      return null;
    }
  }

  async deleteConversation(conversationId) {
    if (!this.currentUser || !this.db) return;
    try {
      // Delete the case doc
      await this.db.collection('cases').doc(conversationId).delete();
      delete this.caseMap[conversationId];
    } catch (error) {
      console.error('deleteConversation error:', error);
    }
  }

  getUserLevel() {
    return this.userProfile?.level || USER_LEVEL.ANONYMOUS;
  }

  getUserName() {
    return this.userProfile?.name || 'Usuario';
  }

  getUserContext() {
    // Return user context for system prompt
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
    
    // Make user info clickable
    if (userInfo && !userInfo.hasClickListener) {
      userInfo.style.cursor = 'pointer';
      userInfo.addEventListener('click', () => this.showAuthModal());
      userInfo.hasClickListener = true;
    }
  }

  showAuthModal() {
    // Create modal if not exists
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
          <h3>👋 Hola, ${this.userProfile.name}!</h3>
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
          <h3>👋 Hola, ${this.userProfile.name || 'amigo'}!</h3>
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
          <h3>🤖 ¡Bienvenido a QueBot!</h3>
          <p>Conéctate para guardar tus conversaciones en la nube</p>
          <div class="auth-buttons">
            <button class="auth-btn google" onclick="queBotAuth.signInWithGoogle().then(() => queBotAuth.hideAuthModal());">
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
    
    // Close on backdrop click
    modal.onclick = (e) => {
      if (e.target === modal) this.hideAuthModal();
    };
  }

  hideAuthModal() {
    const modal = document.getElementById('authModal');
    if (modal) modal.style.display = 'none';
  }

  // Process registration from chat (when user provides info conversationally)
  async processRegistrationFromChat(message) {
    const lowerMsg = message.toLowerCase();
    
    // Simple extraction patterns
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

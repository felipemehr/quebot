/**
 * QueBot - Database Module (Firestore)
 * 9 colecciones: users, tenants, cases, messages, runs, events, feedback, prompt_bundles, sources
 */

class QueBotDatabase {
  constructor() {
    this.db = null;
    this.ready = false;
    this.currentPromptBundleId = null;
  }

  init(firestoreDb) {
    this.db = firestoreDb;
    this.ready = !!this.db;
    if (this.ready) {
      this.getOrCreateDefaultTenant();
      console.log('QueBotDatabase initialized');
    }
    return this.ready;
  }

  isReady() {
    return this.ready && this.db !== null;
  }

  // ========== TENANTS ==========
  async getOrCreateDefaultTenant() {
    if (!this.isReady()) return 'quebot';
    try {
      const tenantRef = this.db.collection('tenants').doc('quebot');
      const doc = await tenantRef.get();
      if (!doc.exists) {
        await tenantRef.set({
          tenant_id: 'quebot',
          brand_name: 'QueBot',
          domain: 'quebot-production.up.railway.app',
          theme: 'dark',
          policy: { trusted_sources_only: true, cost_limit_daily_usd: 10 },
          default_model_profile: 'quality-first',
          created_at: firebase.firestore.FieldValue.serverTimestamp()
        });
        console.log('Default tenant created');
      }
      return 'quebot';
    } catch (e) {
      console.error('Tenant error:', e);
      return 'quebot';
    }
  }

  // ========== USERS ==========
  async saveUser(userId, data) {
    if (!this.isReady()) return;
    try {
      await this.db.collection('users').doc(userId).set({
        user_id: userId,
        tenant_id: data.tenant_id || 'quebot',
        name: data.name || null,
        email: data.email || null,
        photoURL: data.photoURL || null,
        level: data.level || 'anonymous',
        provider: data.provider || null,
        roles: data.roles || ['user'],
        consent_analytics: data.consent_analytics !== undefined ? data.consent_analytics : false,
        consent_whatsapp: data.consent_whatsapp !== undefined ? data.consent_whatsapp : false,
        last_seen_at: firebase.firestore.FieldValue.serverTimestamp(),
        updated_at: firebase.firestore.FieldValue.serverTimestamp()
      }, { merge: true });
    } catch (e) {
      console.error('Save user error:', e);
    }
  }

  async getUser(userId) {
    if (!this.isReady()) return null;
    try {
      const doc = await this.db.collection('users').doc(userId).get();
      return doc.exists ? doc.data() : null;
    } catch (e) {
      console.error('Get user error:', e);
      return null;
    }
  }

  // ========== CASES ==========
  async createCase(userId, channel, title) {
    if (!this.isReady()) return null;
    try {
      const caseRef = this.db.collection('cases').doc();
      await caseRef.set({
        case_id: caseRef.id,
        tenant_id: 'quebot',
        user_id: userId,
        status: 'open',
        channel: channel || 'web',
        vertical: null,
        tags: [],
        title: title || 'Nueva conversación',
        created_at: firebase.firestore.FieldValue.serverTimestamp(),
        updated_at: firebase.firestore.FieldValue.serverTimestamp(),
        closed_at: null
      });
      return caseRef.id;
    } catch (e) {
      console.error('Create case error:', e);
      return null;
    }
  }

  async updateCase(caseId, data) {
    if (!this.isReady()) return;
    try {
      await this.db.collection('cases').doc(caseId).update({
        ...data,
        updated_at: firebase.firestore.FieldValue.serverTimestamp()
      });
    } catch (e) {
      console.error('Update case error:', e);
    }
  }

  async getCases(userId) {
    if (!this.isReady()) return [];
    try {
      const snapshot = await this.db.collection('cases')
        .where('user_id', '==', userId)
        .orderBy('created_at', 'desc')
        .limit(50)
        .get();
      return snapshot.docs.map(doc => ({ id: doc.id, ...doc.data() }));
    } catch (e) {
      console.error('Get cases error:', e);
      return [];
    }
  }

  async deleteCase(caseId) {
    if (!this.isReady()) return;
    try {
      const messages = await this.db.collection('messages')
        .where('case_id', '==', caseId).get();
      const batch = this.db.batch();
      messages.docs.forEach(doc => batch.delete(doc.ref));
      batch.delete(this.db.collection('cases').doc(caseId));
      await batch.commit();
    } catch (e) {
      console.error('Delete case error:', e);
    }
  }

  // ========== MESSAGES ==========
  async addMessage(caseId, role, text, extras) {
    if (!this.isReady()) return null;
    try {
      extras = extras || {};
      const msgRef = this.db.collection('messages').doc();
      await msgRef.set({
        message_id: msgRef.id,
        case_id: caseId,
        role: role,
        text: text,
        ui_payload_ref: extras.ui_payload_ref || null,
        source_refs: extras.source_refs || [],
        created_at: firebase.firestore.FieldValue.serverTimestamp()
      });
      return msgRef.id;
    } catch (e) {
      console.error('Add message error:', e);
      return null;
    }
  }

  async getMessages(caseId) {
    if (!this.isReady()) return [];
    try {
      const snapshot = await this.db.collection('messages')
        .where('case_id', '==', caseId)
        .orderBy('created_at', 'asc')
        .get();
      return snapshot.docs.map(doc => ({ id: doc.id, ...doc.data() }));
    } catch (e) {
      console.error('Get messages error:', e);
      return [];
    }
  }

  // ========== RUNS ==========
  async createRun(caseId, triggerMessageId, metadata) {
    if (!this.isReady()) return null;
    try {
      metadata = metadata || {};
      const runRef = this.db.collection('runs').doc();
      await runRef.set({
        run_id: runRef.id,
        case_id: caseId,
        trigger_message_id: triggerMessageId,
        pipeline_version: '1.0',
        provider: 'anthropic',
        models: {
          reasoner_model: metadata.model || 'claude-sonnet-4-20250514',
          verifier_model: null,
          ui_planner_model: null
        },
        prompt_bundle_id: this.currentPromptBundleId || null,
        timing_ms: {
          total: metadata.timing_total || 0,
          rag: metadata.timing_rag || 0,
          llm: metadata.timing_llm || 0,
          verify: 0,
          ui: 0
        },
        token_usage: {
          input_tokens: metadata.input_tokens || 0,
          output_tokens: metadata.output_tokens || 0
        },
        cost_estimate: metadata.cost_estimate || 0,
        result_flags: {
          verified_ok: null,
          fallback_used: false,
          handoff: false,
          searched: metadata.searched || false,
          legal_used: metadata.legal_used || false
        },
        created_at: firebase.firestore.FieldValue.serverTimestamp()
      });
      return runRef.id;
    } catch (e) {
      console.error('Create run error:', e);
      return null;
    }
  }

  // ========== EVENTS ==========
  async logEvent(caseId, runId, type, payload) {
    if (!this.isReady()) return null;
    try {
      payload = payload || {};
      const eventRef = this.db.collection('events').doc();
      await eventRef.set({
        event_id: eventRef.id,
        case_id: caseId,
        run_id: runId,
        type: type,
        payload: payload,
        timestamp: firebase.firestore.FieldValue.serverTimestamp()
      });
      return eventRef.id;
    } catch (e) {
      console.error('Log event error:', e);
      return null;
    }
  }

  // ========== FEEDBACK ==========
  async addFeedback(caseId, runId, messageId, data) {
    if (!this.isReady()) return null;
    try {
      data = data || {};
      const fbRef = this.db.collection('feedback').doc();
      await fbRef.set({
        feedback_id: fbRef.id,
        case_id: caseId,
        run_id: runId,
        message_id: messageId,
        rating: data.rating || null,
        thumbs: data.thumbs || null,
        reason_codes: data.reason_codes || [],
        free_text: data.free_text || null,
        resolved: false,
        created_at: firebase.firestore.FieldValue.serverTimestamp()
      });
      return fbRef.id;
    } catch (e) {
      console.error('Add feedback error:', e);
      return null;
    }
  }

  // ========== PROMPT BUNDLES ==========
  async getOrCreatePromptBundle(promptText, notes) {
    if (!this.isReady()) return null;
    try {
      notes = notes || '';
      const newHash = this._hashPrompt(promptText);

      // Check for existing active bundle
      const snapshot = await this.db.collection('prompt_bundles')
        .where('status', '==', 'active')
        .orderBy('created_at', 'desc')
        .limit(1)
        .get();

      if (!snapshot.empty) {
        const existing = snapshot.docs[0];
        const data = existing.data();
        if (data.prompt_hash === newHash) {
          this.currentPromptBundleId = existing.id;
          return existing.id;
        }
        // Archive old bundle
        await existing.ref.update({ status: 'archived' });
      }

      // Create new bundle
      const bundleRef = this.db.collection('prompt_bundles').doc();
      await bundleRef.set({
        prompt_bundle_id: bundleRef.id,
        reasoner_prompt: promptText,
        verifier_prompt: '',
        ui_planner_prompt: '',
        prompt_hash: newHash,
        status: 'active',
        notes: notes,
        created_at: firebase.firestore.FieldValue.serverTimestamp()
      });
      this.currentPromptBundleId = bundleRef.id;
      console.log('New prompt bundle created:', bundleRef.id);
      return bundleRef.id;
    } catch (e) {
      console.error('Prompt bundle error:', e);
      return null;
    }
  }

  async getActivePromptBundle() {
    if (!this.isReady()) return null;
    try {
      const snapshot = await this.db.collection('prompt_bundles')
        .where('status', '==', 'active')
        .orderBy('created_at', 'desc')
        .limit(1)
        .get();
      if (snapshot.empty) return null;
      return { id: snapshot.docs[0].id, ...snapshot.docs[0].data() };
    } catch (e) {
      console.error('Get prompt bundle error:', e);
      return null;
    }
  }

  _hashPrompt(text) {
    let hash = 0;
    for (let i = 0; i < text.length; i++) {
      const char = text.charCodeAt(i);
      hash = ((hash << 5) - hash) + char;
      hash = hash & hash;
    }
    return 'h_' + Math.abs(hash).toString(36);
  }

  // ========== SOURCES ==========
  async addSource(data) {
    if (!this.isReady()) return null;
    try {
      data = data || {};
      const srcRef = this.db.collection('sources').doc();
      await srcRef.set({
        source_id: srcRef.id,
        type: data.type || 'web',
        url: data.url || null,
        title: data.title || null,
        trust_level: data.trust_level || 'unknown',
        content_excerpt: data.content_excerpt || null,
        retrieved_at: firebase.firestore.FieldValue.serverTimestamp()
      });
      return srcRef.id;
    } catch (e) {
      console.error('Add source error:', e);
      return null;
    }
  }
}

// Global instance
const queBotDB = new QueBotDatabase();

// ============================================================
// firebase-config.js - Firebase Initialization & Services
// Project: smartgen-db-26
// ============================================================

import { initializeApp } from "https://www.gstatic.com/firebasejs/10.12.0/firebase-app.js";
import { getAnalytics } from "https://www.gstatic.com/firebasejs/10.12.0/firebase-analytics.js";
import { 
  getDatabase, 
  ref, 
  onValue, 
  set, 
  push, 
  get, 
  child,
  remove,
  serverTimestamp 
} from "https://www.gstatic.com/firebasejs/10.12.0/firebase-database.js";

// Konfigurasi Firebase Web App
export const firebaseConfig = {
  apiKey: "AIzaSyDoxT72TbDtKdkhl58gum62f6tZqo6V_vU",
  authDomain: "smartgen-db-26.firebaseapp.com",
  databaseURL: "https://smartgen-db-26-default-rtdb.asia-southeast1.firebasedatabase.app",
  projectId: "smartgen-db-26",
  storageBucket: "smartgen-db-26.firebasestorage.app",
  messagingSenderId: "966580040777",
  appId: "1:966580040777:web:c3140651d6ba697d8cbb98",
  measurementId: "G-K1QDRSCPF1"
};

// Inisialisasi App & Database
export const app = initializeApp(firebaseConfig);
export const analytics = typeof window !== 'undefined' ? getAnalytics(app) : null;
export const db = getDatabase(app);

// Export Firebase Database Helpers
export { ref, onValue, set, push, get, child, remove, serverTimestamp };

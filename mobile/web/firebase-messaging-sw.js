importScripts("https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js");
importScripts("https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js");

firebase.initializeApp({
  apiKey: "AIzaSyAzY1N_NsQXX7bpus4A3BzEaMj-zWJLnFE",
  authDomain: "forsadriver.firebaseapp.com",
  projectId: "forsadriver",
  storageBucket: "forsadriver.firebasestorage.app",
  messagingSenderId: "427860841533",
  appId: "1:427860841533:web:c13be2915d3395d9d60654",
  measurementId: "G-032TBDP0SC"
});

const messaging = firebase.messaging();
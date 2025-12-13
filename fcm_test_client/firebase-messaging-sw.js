importScripts('https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging-compat.js');

// TODO: Replace with your Firebase Config (Same as in index.html)
const firebaseConfig = {
  apiKey: "AIzaSyDcOM_cKKAYcvM8jba33CryCQXpeeENdIQ",
  authDomain: "poliisiauto-fi.firebaseapp.com",
  projectId: "poliisiauto-fi",
  storageBucket: "poliisiauto-fi.firebasestorage.app",
  messagingSenderId: "707687366320",
  appId: "1:707687366320:web:d16b00b9580be0d80ac575"
};

firebase.initializeApp(firebaseConfig);

const messaging = firebase.messaging();

messaging.onBackgroundMessage((payload) => {
  console.log('[firebase-messaging-sw.js] Received background message ', payload);

  const notificationTitle = payload.notification.title;
  const notificationOptions = {
    body: payload.notification.body,
    icon: '/firebase-logo.png' // Optional
  };

  self.registration.showNotification(notificationTitle, notificationOptions);
});

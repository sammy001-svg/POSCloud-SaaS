/**
 * POS Offline Database Helper (IndexedDB)
 */
const DB_NAME = 'POSCloudDB';
const DB_VERSION = 1;

let db;

const initDB = () => {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = (e) => {
            const db = e.target.result;
            // Store for Products
            if (!db.objectStoreNames.contains('products')) {
                db.createObjectStore('products', { keyPath: 'id' });
            }
            // Store for Pending Sales (Offline)
            if (!db.objectStoreNames.contains('pending_sales')) {
                db.createObjectStore('pending_sales', { keyPath: 'temp_id', autoIncrement: true });
            }
        };

        request.onsuccess = (e) => {
            db = e.target.result;
            resolve(db);
        };

        request.onerror = (e) => reject(e.target.error);
    });
};

// --- Product Operations ---
const saveProductsLocal = async (products) => {
    const tx = db.transaction('products', 'readwrite');
    const store = tx.objectStore('products');
    await store.clear();
    products.forEach(p => store.put(p));
    return tx.complete;
};

const getProductsLocal = () => {
    return new Promise((resolve) => {
        const tx = db.transaction('products', 'readonly');
        const store = tx.objectStore('products');
        const request = store.getAll();
        request.onsuccess = () => resolve(request.result);
    });
};

// --- Sales Operations ---
const saveSaleOffline = async (saleData) => {
    const tx = db.transaction('pending_sales', 'readwrite');
    const store = tx.objectStore('pending_sales');
    store.add({
        ...saleData,
        created_at: new Date().toISOString(),
        synced: 0
    });
    return tx.complete;
};

const getPendingSales = () => {
    return new Promise((resolve) => {
        const tx = db.transaction('pending_sales', 'readonly');
        const store = tx.objectStore('pending_sales');
        const request = store.getAll();
        request.onsuccess = () => resolve(request.result);
    });
};

const deletePendingSale = (tempId) => {
    const tx = db.transaction('pending_sales', 'readwrite');
    const store = tx.objectStore('pending_sales');
    store.delete(tempId);
};

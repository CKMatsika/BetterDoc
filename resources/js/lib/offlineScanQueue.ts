export interface OfflineQueuedScan {
    id: string;
    filename: string;
    title: string;
    cabinet_id: string;
    classification_level_id: string;
    auto_split_barcode: boolean;
    auto_deskew: boolean;
    force_invoice?: boolean;
    blob: Blob;
    queued_at: string;
}

const DB_NAME = 'betterdoc_offline_scans_db';
const STORE_NAME = 'pending_scans';

function openDb(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { keyPath: 'id' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

export async function enqueueOfflineScan(scan: OfflineQueuedScan): Promise<void> {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).put(scan);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

export async function listOfflineScans(): Promise<OfflineQueuedScan[]> {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readonly');
        const req = tx.objectStore(STORE_NAME).getAll();
        req.onsuccess = () => resolve((req.result as OfflineQueuedScan[]) ?? []);
        req.onerror = () => reject(req.error);
    });
}

export async function removeOfflineScan(id: string): Promise<void> {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).delete(id);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

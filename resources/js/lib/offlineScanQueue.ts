export interface OfflineQueuedScan {
    id: string;
    filename: string;
    mime_type: string;
    size_bytes: number;
    cabinet_id: string;
    classification_level_id: string;
    title: string;
    auto_split_barcode: boolean;
    auto_deskew: boolean;
    queued_at: string;
    blob: Blob;
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

export async function enqueueOfflineScan(item: OfflineQueuedScan): Promise<void> {
    const db = await openDb();
    await new Promise<void>((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).put(item);
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
    await new Promise<void>((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).delete(id);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

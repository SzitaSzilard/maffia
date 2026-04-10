/**
 * NetMafia Realtime Module
 * 
 * Kliens oldali WebSocket kezelő az ARCHITECTURE.md-ben leírt
 * Lifecycle API-t követve (init/destroy).
 * 
 * Használat:
 *   NetMafiaRealtime.init({ userId: 123, token: 'abc' });
 *   NetMafiaRealtime.joinRoom('kocsma');
 *   NetMafiaRealtime.destroy();
 */

const NetMafiaRealtime = (function () {
    'use strict';

    // Privát változók
    let socket = null;
    let userId = null;
    let token = null;
    let reconnectAttempts = 0;
    let reconnectTimer = null;
    let pingTimer = null;
    let isConnected = false;
    let rooms = new Set();
    let eventHandlers = {};

    // Konfiguráció
    const config = {
        wsUrl: 'ws://' + window.location.hostname + ':8080',
        maxReconnectAttempts: 10,
        reconnectDelay: 3000,
        pingInterval: 30000
    };

    /**
     * Inicializálás - WebSocket kapcsolat létrehozása
     */
    function init(options = {}) {
        if (socket && socket.readyState === WebSocket.OPEN) {
            console.log('[Realtime] Már csatlakozva');
            return;
        }

        userId = options.userId || null;
        token = options.token || null;

        connect();
    }

    /**
     * WebSocket kapcsolat létrehozása
     */
    function connect() {
        try {
            socket = new WebSocket(config.wsUrl);

            socket.onopen = handleOpen;
            socket.onmessage = handleMessage;
            socket.onclose = handleClose;
            socket.onerror = handleError;

        } catch (error) {
            console.error('[Realtime] Kapcsolódási hiba:', error);
            scheduleReconnect();
        }
    }

    /**
     * Kapcsolat megnyitásakor
     */
    function handleOpen() {
        console.log('[Realtime] Kapcsolódva');
        isConnected = true;
        reconnectAttempts = 0;

        // Autentikáció küldése
        if (userId && token) {
            send({
                type: 'auth',
                user_id: userId,
                token: token
            });
        }

        // Korábbi szobák újracsatlakozása
        rooms.forEach(room => {
            send({
                type: 'join_room',
                room: room
            });
        });

        // Ping timer indítása
        startPingTimer();

        // Esemény kiváltása
        emit('connected');
    }

    /**
     * Üzenet fogadásakor
     */
    function handleMessage(event) {
        try {
            const data = JSON.parse(event.data);

            switch (data.type) {
                case 'auth_success':
                    console.log('[Realtime] Autentikáció sikeres');
                    emit('auth_success', data);
                    break;

                case 'auth_error':
                    console.error('[Realtime] Autentikációs hiba:', data.message);
                    emit('auth_error', data);
                    break;

                case 'room_joined':
                    console.log('[Realtime] Szobához csatlakozva:', data.room);
                    emit('room_joined', data);
                    break;

                case 'room_left':
                    console.log('[Realtime] Szoba elhagyva:', data.room);
                    emit('room_left', data);
                    break;

                case 'message':
                    emit('message', data);
                    emit('message:' + data.room, data);
                    break;

                case 'pong':
                    // Ping response, minden OK
                    break;

                default:
                    // Egyéb üzenetek továbbítása
                    emit(data.type, data);
            }

        } catch (error) {
            console.error('[Realtime] Üzenet feldolgozási hiba:', error);
        }
    }

    /**
     * Kapcsolat bezárásakor
     */
    function handleClose(event) {
        console.log('[Realtime] Kapcsolat lezárva:', event.code);
        isConnected = false;
        stopPingTimer();

        emit('disconnected', { code: event.code });

        // Automatikus újracsatlakozás (hacsak nem szándékos lecsatlakozás)
        if (event.code !== 1000) {
            scheduleReconnect();
        }
    }

    /**
     * Hiba kezelése
     */
    function handleError(error) {
        console.error('[Realtime] WebSocket hiba:', error);
        emit('error', error);
    }

    /**
     * Újracsatlakozás ütemezése
     */
    function scheduleReconnect() {
        if (reconnectAttempts >= config.maxReconnectAttempts) {
            console.error('[Realtime] Maximális újracsatlakozási kísérletek elérve');
            emit('reconnect_failed');
            return;
        }

        reconnectAttempts++;
        const delay = config.reconnectDelay * Math.min(reconnectAttempts, 5);

        console.log(`[Realtime] Újracsatlakozás ${delay}ms múlva... (${reconnectAttempts}. kísérlet)`);

        reconnectTimer = setTimeout(() => {
            connect();
        }, delay);
    }

    /**
     * Ping timer indítása (kapcsolat életben tartása)
     */
    function startPingTimer() {
        stopPingTimer();
        pingTimer = setInterval(() => {
            if (isConnected) {
                send({ type: 'ping' });
            }
        }, config.pingInterval);
    }

    /**
     * Ping timer leállítása
     */
    function stopPingTimer() {
        if (pingTimer) {
            clearInterval(pingTimer);
            pingTimer = null;
        }
    }

    /**
     * Üzenet küldése
     */
    function send(data) {
        if (!socket || socket.readyState !== WebSocket.OPEN) {
            console.warn('[Realtime] Nem csatlakozva, üzenet nem küldhető');
            return false;
        }

        socket.send(JSON.stringify(data));
        return true;
    }

    /**
     * Szobához csatlakozás
     */
    function joinRoom(roomName) {
        rooms.add(roomName);

        if (isConnected) {
            send({
                type: 'join_room',
                room: roomName
            });
        }
    }

    /**
     * Szoba elhagyása
     */
    function leaveRoom(roomName) {
        rooms.delete(roomName);

        if (isConnected) {
            send({
                type: 'leave_room',
                room: roomName
            });
        }
    }

    /**
     * Üzenet küldése szobába
     */
    function sendToRoom(roomName, content) {
        return send({
            type: 'message',
            room: roomName,
            content: content
        });
    }

    /**
     * Eseménykezelő regisztrálása
     */
    function on(eventName, callback) {
        if (!eventHandlers[eventName]) {
            eventHandlers[eventName] = [];
        }
        eventHandlers[eventName].push(callback);
    }

    /**
     * Eseménykezelő eltávolítása
     */
    function off(eventName, callback) {
        if (!eventHandlers[eventName]) {
            return;
        }

        if (callback) {
            eventHandlers[eventName] = eventHandlers[eventName].filter(h => h !== callback);
        } else {
            delete eventHandlers[eventName];
        }
    }

    /**
     * Esemény kiváltása
     */
    function emit(eventName, data = null) {
        if (!eventHandlers[eventName]) {
            return;
        }

        eventHandlers[eventName].forEach(callback => {
            try {
                callback(data);
            } catch (error) {
                console.error('[Realtime] Eseménykezelő hiba:', error);
            }
        });
    }

    /**
     * Takarítás - kapcsolat bezárása és erőforrások felszabadítása
     */
    function destroy() {
        console.log('[Realtime] Leállítás...');

        // Timerek leállítása
        if (reconnectTimer) {
            clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }
        stopPingTimer();

        // Szobák törlése
        rooms.clear();

        // Eseménykezelők törlése
        eventHandlers = {};

        // Socket bezárása
        if (socket) {
            socket.close(1000, 'Normál leállítás');
            socket = null;
        }

        isConnected = false;
        reconnectAttempts = 0;
    }

    /**
     * Állapot lekérdezése
     */
    function getState() {
        return {
            isConnected: isConnected,
            userId: userId,
            rooms: Array.from(rooms),
            reconnectAttempts: reconnectAttempts
        };
    }

    // Publikus API
    return {
        init: init,
        destroy: destroy,
        send: send,
        joinRoom: joinRoom,
        leaveRoom: leaveRoom,
        sendToRoom: sendToRoom,
        on: on,
        off: off,
        getState: getState,

        // Rövidítések
        connect: connect,
        disconnect: destroy
    };

})();

// Globálisan elérhetővé tétel
if (typeof window !== 'undefined') {
    window.NetMafiaRealtime = NetMafiaRealtime;
}

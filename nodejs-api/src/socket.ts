import { Server } from "socket.io";
import type { Server as HttpServer } from "http";
import { verifyToken } from "./lib/auth";

let io: Server | null = null;

export function initSocket(httpServer: HttpServer) {
  io = new Server(httpServer, { cors: { origin: true, credentials: true } });
  io.use((socket, next) => {
    try {
      const token = socket.handshake.auth?.token as string | undefined;
      if (!token) return next(new Error("unauthorized"));
      (socket as unknown as { user: unknown }).user = verifyToken(token);
      next();
    } catch {
      next(new Error("unauthorized"));
    }
  });
  io.on("connection", () => {});
  return io;
}

/** Realtime events: "kot" (kitchen queue), "rooms" (live room status), "orders", "menu" (sold-out changes). */
export function emit(event: "kot" | "rooms" | "orders" | "menu", payload: unknown) {
  io?.emit(event, payload);
}

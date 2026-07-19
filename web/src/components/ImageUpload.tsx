import { useCallback, useEffect, useRef, useState } from "react";
import { Image as ImageIcon } from "lucide-react";
import clsx from "clsx";

/**
 * Generic drag & drop / paste / browse image picker. The chosen image is
 * downscaled and re-encoded inline as a data URI (no separate file host
 * needed) — used for both the hotel logo (Settings → Hotel identity) and
 * menu item thumbnails (Menu admin → Point of Sale).
 */
export function ImageDropUpload({
  value,
  onChange,
  maxBox = 320,
  removeLabel = "Remove image",
  previewClassName = "max-h-20 max-w-[160px] object-contain",
}: {
  value: string;
  onChange: (dataUrl: string) => void;
  maxBox?: number;
  removeLabel?: string;
  previewClassName?: string;
}) {
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");
  const [dragOver, setDragOver] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const handleFile = useCallback(
    async (file: File | null | undefined) => {
      if (!file) return;
      if (!file.type.startsWith("image/")) {
        setErr("Please choose an image file.");
        return;
      }
      setErr("");
      setBusy(true);
      try {
        onChange(await fileToImageDataUrl(file, maxBox));
      } catch {
        setErr("Could not read that image.");
      } finally {
        setBusy(false);
      }
    },
    [onChange, maxBox],
  );

  // Paste an image anywhere on the page while this field is on screen. Gating
  // this on the drop zone being focused doesn't work: clicking it to focus it
  // also opens the native file picker (see onClick below), which blurs the
  // zone and detaches the listener before Ctrl/⌘+V can be pressed.
  useEffect(() => {
    const onPaste = (e: ClipboardEvent) => {
      const item = Array.from(e.clipboardData?.items ?? []).find((i) => i.type.startsWith("image/"));
      if (item) {
        e.preventDefault();
        void handleFile(item.getAsFile());
      }
    };
    document.addEventListener("paste", onPaste);
    return () => document.removeEventListener("paste", onPaste);
  }, [handleFile]);

  return (
    <div>
      <div
        role="button"
        tabIndex={0}
        onClick={() => inputRef.current?.click()}
        onKeyDown={(e) => (e.key === "Enter" || e.key === " ") && inputRef.current?.click()}
        onDragOver={(e) => {
          e.preventDefault();
          setDragOver(true);
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDragOver(false);
          void handleFile(e.dataTransfer.files?.[0]);
        }}
        className={clsx(
          "flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-4 text-center outline-none transition",
          dragOver ? "border-brand-500 bg-brand-50" : "border-slate-300 hover:border-brand-400 focus:border-brand-500",
        )}
      >
        {value ? (
          <img src={value} alt="Preview" className={previewClassName} />
        ) : (
          <ImageIcon className="h-8 w-8 text-slate-300" />
        )}
        <div className="text-xs text-slate-500">
          {busy ? (
            "Processing…"
          ) : (
            <>
              Drag &amp; drop, paste, or <span className="font-semibold text-brand-600">browse</span>
            </>
          )}
        </div>
      </div>
      <input
        ref={inputRef}
        type="file"
        accept="image/*"
        className="hidden"
        onChange={(e) => {
          void handleFile(e.target.files?.[0]);
          e.target.value = ""; // allow re-selecting the same file
        }}
      />
      {value && (
        <button className="mt-1.5 text-xs font-semibold text-red-500 hover:text-red-600" onClick={() => onChange("")}>
          {removeLabel}
        </button>
      )}
      {err && <p className="mt-1 text-xs text-red-500">{err}</p>}
    </div>
  );
}

/**
 * Turn a chosen file into a small data URI. SVGs are kept as-is (crisp &
 * tiny); raster images are downscaled to fit a `maxBox`px box and re-encoded
 * as PNG so the stored value stays small.
 */
export async function fileToImageDataUrl(file: File, maxBox = 320): Promise<string> {
  const dataUrl = await readAsDataUrl(file);
  if (file.type === "image/svg+xml") return dataUrl;

  const img = await loadImage(dataUrl);
  const scale = Math.min(1, maxBox / Math.max(img.width, img.height));
  const w = Math.max(1, Math.round(img.width * scale));
  const h = Math.max(1, Math.round(img.height * scale));
  const canvas = document.createElement("canvas");
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext("2d");
  if (!ctx) return dataUrl;
  ctx.drawImage(img, 0, 0, w, h);
  return canvas.toDataURL("image/png");
}

function readAsDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error("read failed"));
    reader.readAsDataURL(file);
  });
}

function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error("load failed"));
    img.src = src;
  });
}

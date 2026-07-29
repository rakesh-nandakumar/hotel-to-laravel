import { Building2 } from "lucide-react";
import clsx from "clsx";
import { brandInitials } from "../lib/branding";

interface BrandLogoProps {
  logo?: string;
  name?: string;
  size?: "sm" | "md" | "lg" | "xl";
  className?: string;
  /**
   * - "badge": Elevated crisp white card tile. Seamlessly accommodates JPEG logos (white background blends in),
   *   transparent PNGs, rectangular shapes, and circular badges while keeping aspect ratios intact.
   * - "plain": Clean object-contain image without the card wrapper.
   */
  variant?: "badge" | "plain";
}

const SIZE_MAP = {
  sm: "h-9 w-9 rounded-xl p-1",
  md: "h-10 w-10 rounded-xl p-1.5",
  lg: "h-12 w-12 rounded-xl p-2",
  xl: "h-24 w-24 rounded-2xl p-3",
};

const ICON_SIZE_MAP = {
  sm: "h-5 w-5",
  md: "h-5 w-5",
  lg: "h-6 w-6",
  xl: "h-11 w-11",
};

export function BrandLogo({
  logo,
  name = "Hotel",
  size = "md",
  className,
  variant = "badge",
}: BrandLogoProps) {
  if (!logo) {
    if (variant === "badge") {
      return (
        <div
          className={clsx(
            "flex shrink-0 items-center justify-center bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-md ring-1 ring-white/20",
            SIZE_MAP[size],
            className,
          )}
        >
          {size === "xl" ? (
            <Building2 className={ICON_SIZE_MAP[size]} />
          ) : (
            <span className="font-black text-white leading-none">
              {brandInitials(name)}
            </span>
          )}
        </div>
      );
    }
    return (
      <div
        className={clsx(
          "flex shrink-0 items-center justify-center rounded-lg bg-brand-600 text-white",
          SIZE_MAP[size],
          className,
        )}
      >
        <Building2 className={ICON_SIZE_MAP[size]} />
      </div>
    );
  }

  if (variant === "plain") {
    return (
      <img
        src={logo}
        alt={name}
        className={clsx("object-contain shrink-0", className)}
      />
    );
  }

  return (
    <div
      className={clsx(
        "relative flex shrink-0 items-center justify-center overflow-hidden bg-white shadow-lg ring-1 ring-black/10 transition-all hover:shadow-xl",
        SIZE_MAP[size],
        className,
      )}
    >
      <img
        src={logo}
        alt={name}
        className="h-full w-full object-contain select-none"
      />
    </div>
  );
}

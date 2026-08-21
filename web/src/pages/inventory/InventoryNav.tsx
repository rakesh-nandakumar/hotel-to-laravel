import { useNavigate } from "react-router-dom";
import { Tabs } from "../../components/ui";
import { useAuth } from "../../lib/auth";

type TabId = "ingredients" | "products" | "grn";

const PATHS: Record<TabId, string> = {
  ingredients: "/inventory",
  products: "/inventory/products",
  grn: "/inventory/grn",
};

/** Shared sub-nav across the three Inventory pages — each is its own guarded route (independent permissions), so switching tabs navigates rather than toggling local state. */
export function InventoryNav({ active }: { active: TabId }) {
  const navigate = useNavigate();
  const { can } = useAuth();

  const tabs: { id: TabId; label: string }[] = [
    can("hotel_ingredients.access") && { id: "ingredients" as const, label: "Ingredients" },
    can("hotel_products.access") && { id: "products" as const, label: "Products" },
    can("hotel_grn.access") && { id: "grn" as const, label: "Goods Received" },
  ].filter((t): t is { id: TabId; label: string } => Boolean(t));

  if (tabs.length <= 1) return null;

  return <Tabs tabs={tabs} active={active} onChange={(id) => navigate(PATHS[id])} />;
}

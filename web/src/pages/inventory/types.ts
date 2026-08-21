/** Shared shapes for the Ingredients/Products tabs — both are `Ingredient` rows behind a `kind`. */
export type Batch = {
  id: number;
  qty: number;
  initial_qty: number;
  unit_cost: number | null;
  batch_no: string | null;
  manufactured_at: string | null;
  expiry_date: string | null;
  received_at: string;
  note?: string | null;
};

export type StockItem = {
  id: number;
  name: string;
  unit: string;
  stock_qty: number;
  low_stock_threshold: number;
  low: boolean;
  unit_cost: number | null;
  selling_price: number | null;
  menu_category_id: number | null;
  image: string | null;
  active: boolean;
  kind?: { id: number; code: string };
  next_expiry?: string | null;
  has_expired: boolean;
  used_in: string[];
  batches: Batch[];
};

export type ExpiryBatch = Batch & { days_left: number; expired: boolean; ingredient: { name: string; unit: string } };

export type StockItemsPage = {
  ingredients: StockItem[];
  total: number;
  page: number;
  page_size: number;
  counts: { total: number; low: number; expiry_tracked: number; untracked: number };
};

export type MenuCategoryLite = { id: number; name: string };

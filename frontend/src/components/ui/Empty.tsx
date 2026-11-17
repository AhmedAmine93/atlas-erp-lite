import { Boxes } from "lucide-react";

export default function Empty({
  text = "Aucun résultat pour le moment.",
}: {
  text?: string;
}) {
  return (
    <div className="empty">
      <Boxes size={32} />
      <p>{text}</p>
    </div>
  );
}
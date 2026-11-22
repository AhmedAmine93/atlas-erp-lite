"use client";

export type DeclarativeField = {
  key: string;
  label: string;
  type: "text" | "email" | "number" | "date" | "select" | "checkbox";
  required: boolean;
  options: string[];
};
export type DeclarativeFormSchema = { version: 1; name: string; fields: DeclarativeField[] };

const types = new Set(["text", "email", "number", "date", "select", "checkbox"]);

/**
 * P071.01 first increment. Render semantic, inert HTML form controls only.
 * A schema does not authorize writing any table or executing user code.
 */
export default function DeclarativeFormPreview({ schema }: { schema: DeclarativeFormSchema }) {
  if (schema.version !== 1 || !Array.isArray(schema.fields) || schema.fields.length > 25) {
    return <p role="alert">Schéma de formulaire non pris en charge.</p>;
  }
  return (
    <section aria-label={schema.name} className="atlas-form-preview">
      <h2>{schema.name}</h2>
      <form onSubmit={(event) => event.preventDefault()}>
        {schema.fields.filter((field) => types.has(field.type)).map((field) => (
          <label key={field.key}>
            <span>{field.label}</span>
            {field.type === "select" ? (
              <select required={field.required} defaultValue="">
                <option value="" disabled>Choisir une option</option>
                {field.options.slice(0, 20).map((option) => <option key={option} value={option}>{option}</option>)}
              </select>
            ) : (
              <input type={field.type} required={field.required} />
            )}
          </label>
        ))}
        <p>Prévisualisation uniquement — aucune donnée n’est enregistrée.</p>
      </form>
    </section>
  );
}

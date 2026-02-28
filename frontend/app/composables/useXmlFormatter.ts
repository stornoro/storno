/**
 * XML formatting and syntax highlighting composable.
 *
 * Uses browser-native DOMParser for pretty-printing and regex-based
 * tokenization for syntax highlighting. All user content is HTML-escaped
 * before wrapping in <span> elements, so the output is safe for v-html.
 */
export function useXmlFormatter() {
  /**
   * Pretty-print XML with 2-space indentation using DOMParser.
   * Falls back to original string if parsing fails.
   */
  function formatXml(xml: string): string {
    if (!xml?.trim()) return xml

    try {
      const parser = new DOMParser()
      const doc = parser.parseFromString(xml, 'application/xml')
      const errorNode = doc.querySelector('parsererror')
      if (errorNode) return xml

      return serializeNode(doc, 0).trimStart()
    }
    catch {
      return xml
    }
  }

  function serializeNode(node: Node, depth: number): string {
    const indent = '  '.repeat(depth)

    if (node.nodeType === Node.TEXT_NODE) {
      const text = node.textContent?.trim() ?? ''
      return text ? `${indent}${text}\n` : ''
    }

    if (node.nodeType === Node.COMMENT_NODE) {
      return `${indent}<!--${node.textContent}-->\n`
    }

    if (node.nodeType === Node.PROCESSING_INSTRUCTION_NODE) {
      const pi = node as ProcessingInstruction
      return `${indent}<?${pi.target} ${pi.data}?>\n`
    }

    if (node.nodeType === Node.DOCUMENT_NODE) {
      let result = ''
      // Add XML declaration if original had one
      result += `${indent}<?xml version="1.0" encoding="UTF-8"?>\n`
      for (const child of Array.from(node.childNodes)) {
        result += serializeNode(child, depth)
      }
      return result
    }

    if (node.nodeType === Node.ELEMENT_NODE) {
      const el = node as Element
      const tagName = el.tagName
      let attrs = ''

      for (const attr of Array.from(el.attributes)) {
        attrs += ` ${attr.name}="${attr.value}"`
      }

      const children = Array.from(el.childNodes)
      const meaningfulChildren = children.filter((c) => {
        if (c.nodeType === Node.TEXT_NODE) return c.textContent?.trim()
        return true
      })

      if (meaningfulChildren.length === 0) {
        return `${indent}<${tagName}${attrs}/>\n`
      }

      // Single text child — inline it
      if (meaningfulChildren.length === 1 && meaningfulChildren[0].nodeType === Node.TEXT_NODE) {
        const text = meaningfulChildren[0].textContent?.trim() ?? ''
        return `${indent}<${tagName}${attrs}>${text}</${tagName}>\n`
      }

      let result = `${indent}<${tagName}${attrs}>\n`
      for (const child of children) {
        result += serializeNode(child, depth + 1)
      }
      result += `${indent}</${tagName}>\n`
      return result
    }

    return ''
  }

  /**
   * Apply syntax highlighting to already-formatted XML.
   * HTML-escapes all content first, then wraps tokens in <span> elements.
   */
  function highlightXml(formattedXml: string): string {
    if (!formattedXml) return formattedXml

    // HTML-escape everything first
    let escaped = formattedXml
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')

    // XML prolog: <?xml ... ?>
    escaped = escaped.replace(
      /&lt;\?([\s\S]*?)\?&gt;/g,
      '<span class="xml-prolog">&lt;?$1?&gt;</span>',
    )

    // Comments: <!-- ... -->
    escaped = escaped.replace(
      /&lt;!--([\s\S]*?)--&gt;/g,
      '<span class="xml-comment">&lt;!--$1--&gt;</span>',
    )

    // Self-closing tags: <tagName attr="val" />
    escaped = escaped.replace(
      /&lt;([\w:.-]+)((?:\s+[\w:.-]+\s*=\s*&quot;[^&]*&quot;)*)\s*\/&gt;/g,
      (_match, tag, attrs) => {
        const highlightedAttrs = highlightAttrs(attrs)
        return `<span class="xml-tag">&lt;<span class="xml-tag-name">${tag}</span>${highlightedAttrs}/&gt;</span>`
      },
    )

    // Closing tags: </tagName>
    escaped = escaped.replace(
      /&lt;\/([\w:.-]+)&gt;/g,
      '<span class="xml-tag">&lt;/<span class="xml-tag-name">$1</span>&gt;</span>',
    )

    // Opening tags: <tagName attr="val">
    escaped = escaped.replace(
      /&lt;([\w:.-]+)((?:\s+[\w:.-]+\s*=\s*&quot;[^&]*&quot;)*)(\s*)&gt;/g,
      (_match, tag, attrs, space) => {
        const highlightedAttrs = highlightAttrs(attrs)
        return `<span class="xml-tag">&lt;<span class="xml-tag-name">${tag}</span>${highlightedAttrs}${space}&gt;</span>`
      },
    )

    return escaped
  }

  function highlightAttrs(attrs: string): string {
    if (!attrs) return ''
    return attrs.replace(
      /([\w:.-]+)\s*=\s*(&quot;)([^&]*)(&quot;)/g,
      '<span class="xml-attr-name">$1</span>=<span class="xml-attr-value">&quot;$3&quot;</span>',
    )
  }

  /**
   * Format and highlight XML in one call.
   */
  function formatAndHighlight(xml: string): string {
    return highlightXml(formatXml(xml))
  }

  return {
    formatXml,
    highlightXml,
    formatAndHighlight,
  }
}

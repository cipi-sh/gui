<?php

namespace CipiGui\Infrastructure;

/**
 * Namecheap's XML API: one address, a Command and the account in the query,
 * from an address on the account's allowlist. Answers come back as arrays
 * of the attributes of the elements asked for.
 */
class NamecheapClient extends ScopedClient
{
    /**
     * The CommandResponse of a command, as XML.
     *
     * @param  array<string, scalar|null>  $params
     */
    public function command(string $command, array $params = []): \SimpleXMLElement
    {
        $query = http_build_query([
            'ApiUser' => $this->scope('user'),
            'ApiKey' => $this->scope('key'),
            'UserName' => $this->scope('username'),
            'ClientIp' => $this->scope('ip'),
            'Command' => $command,
            ...array_filter($params, fn ($v) => $v !== null),
        ], '', '&', PHP_QUERY_RFC3986);

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($this->raw($query), \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new ConnectorException(__('Namecheap answered with something that is not XML.'));
        }

        if ((string) $xml['Status'] !== 'OK') {
            $error = $xml->Errors->Error[0] ?? null;

            throw new ConnectorException(trim('Namecheap '.($error !== null ? (string) $error['Number'].': '.trim((string) $error) : __('refused the command.'))));
        }

        return $xml->CommandResponse;
    }

    /**
     * The attributes of every $name element under $parent (or the response itself).
     *
     * @return list<array<string, string>>
     */
    public static function attributes(?\SimpleXMLElement $parent, string $name): array
    {
        $rows = [];

        if ($parent === null) {
            return $rows;
        }

        foreach ($parent->{$name} as $element) {
            $row = [];

            foreach ($element->attributes() as $key => $value) {
                $row[(string) $key] = (string) $value;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** The raw answer to a query. Tests stand in here. */
    protected function raw(string $query): string
    {
        return $this->text('/xml.response?'.$query);
    }
}

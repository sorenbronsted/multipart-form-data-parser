<?php declare(strict_types=1);

namespace Kekos\MultipartFormDataParser;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;

class Parser
{
    const CONTENT_TYPE_MULTIPART = 'multipart/form-data';

    /** @var string */
    private $raw_data;
    /** @var string */
    private $content_type_header;
    /** @var UploadedFileFactoryInterface */
    private $uploaded_file_factory;
    /** @var StreamFactoryInterface */
    private $stream_factory;
    /** @var string */
    private $boundary;
    /** @var array */
    private $form_fields = [];
    /** @var array */
    private $files = [];
    /** @var UploadedFileInterface[] */
    private $files_flat = [];

    public function __construct(
        string $raw_data,
        string $content_type_header,
        UploadedFileFactoryInterface $uploaded_file_factory,
        StreamFactoryInterface $stream_factory
    )
    {
        $this->raw_data = $raw_data;
        $this->content_type_header = $content_type_header;
        $this->uploaded_file_factory = $uploaded_file_factory;
        $this->stream_factory = $stream_factory;

        $this->readBoundary();
        $this->parse();
    }

    private function readBoundary(): void
    {
        $content_type_parts = explode(';', $this->content_type_header);
        $content_type_parts = array_map('trim', $content_type_parts);

        if ($content_type_parts[0] !== self::CONTENT_TYPE_MULTIPART) {
            throw ParserException::wrongContentType($content_type_parts[0]);
        }

        if (!isset($content_type_parts[1]) || strpos($content_type_parts[1], 'boundary=') !== 0) {
            throw ParserException::missingBoundary();
        }

        $this->boundary = trim(substr($content_type_parts[1], 9), '"');
    }

    private function parse()
    {
        $form_fields_spec = [];
        $files_spec = [];
        $boundary_pattern = sprintf("/(\r\n)?--%s\s*?(\r\n)?/", preg_quote($this->boundary, '/'));
        $parts = preg_split($boundary_pattern, $this->raw_data);
        if (!is_array($parts)) {
            throw ParserException::splitRegex($boundary_pattern);
        }

        array_shift($parts);
        array_pop($parts);

        foreach ($parts as $i => $part) {
            $part_message = explode("\r\n\r\n", $part);

            if (count($part_message) < 2) {
                // Missing headers are not a fault according to RFC 2046,
                // but we can't process this part without it.
                continue;
            }

            list($headers, $body) = $part_message;
            unset($part_message);
            $headers = $this->parseHeaders($headers);
            $disposition = $headers['content-disposition'] ?? null;

            if ($disposition === null || !$this->isFormDataPart($disposition)) {
                continue;
            }

            if ($filename = $disposition->getKeyValue('filename')) {
                $this->parseUploadedFile($files_spec, $headers, $disposition, $body, $filename);
                continue;
            }

            $form_name = $disposition->getKeyValue('name');
            if ($form_name === null || $form_name === '') {
                continue;
            }

            $form_fields_spec[] = sprintf(
                '%s=%s',
                rawurlencode($form_name),
                rawurlencode($body)
            );
        }

        $form_fields_spec = implode('&', $form_fields_spec);
        parse_str($form_fields_spec, $this->form_fields);

        $this->layoutFilesAssocDeep($files_spec);
    }

    private function isFormDataPart(HttpHeaderLine $content_disposition): bool
    {
        if ($content_disposition->getValue() !== 'form-data') {
            return false;
        }

        return true;
    }

    /**
     * @param string $header_data
     * @return array<string, HttpHeaderLine>|HttpHeaderLine[]
     * @throws ParserException
     */
    private function parseHeaders(string $header_data): array
    {
        $raw_headers = explode("\r\n", $header_data);
        $headers = [];

        foreach ($raw_headers as $raw_header_line) {
            $header = new HttpHeaderLine($raw_header_line);

            $name = strtolower($header->getName());
            $headers[$name] = $header;
        }

        return $headers;
    }

    /**
     * @param array $files_spec
     * @param array<string, HttpHeaderLine>|HttpHeaderLine[] $headers
     * @param HttpHeaderLine $disposition
     * @param string $body
     * @param string $filename
     */
    private function parseUploadedFile(
        array &$files_spec,
        array &$headers,
        HttpHeaderLine $disposition,
        string &$body,
        string $filename
    ): void
    {
        static $file_index = 0;

        $content_type = 'text/plain';
        if (isset($headers['content-type'])) {
            $content_type = $headers['content-type']->getValue();
        }

        $uploaded_file = $this->uploaded_file_factory->createUploadedFile(
            $this->stream_factory->createStream($body),
            strlen($body),
            UPLOAD_ERR_OK,
            $filename,
            $content_type
        );

        $form_name = $disposition->getKeyValue('name');
        if ($form_name === null) {
            $form_name = $file_index++;
        }/* elseif (strpos($form_name, '[') > 0 && strrpos($form_name, ']') === strlen($form_name)) {
            $name_array = explode('[', $form_name);
        }*/

        $object_id = spl_object_id($uploaded_file);
        $files_spec[] = sprintf('%s=%d', rawurlencode($form_name), $object_id);
        $this->files_flat[$object_id] = $uploaded_file;
    }

    private function layoutFilesAssocDeep(array &$files_spec)
    {
        $files_spec = implode('&', $files_spec);
        parse_str($files_spec, $this->files);

        array_walk_recursive($this->files, function (&$value): void {
            $value = $this->files_flat[$value];
        });
    }

    public function getBoundary(): string
    {
        return $this->boundary;
    }

    /**
     * Returns the posted form fields as associative array like PHP's built in
     * $_POST super global.
     *
     * @return array
     */
    public function getFormFields(): array
    {
        return $this->form_fields;
    }

    /**
     * Returns the posted files as associative array with objects of type
     * UploadedFileFactoryInterface as leafs.
     *
     * @return array
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    public function decorateRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request
            ->withParsedBody($this->form_fields)
            ->withUploadedFiles($this->files);
    }

    public static function createFromRequest(
        ServerRequestInterface $request,
        UploadedFileFactoryInterface $uploaded_file_factory,
        StreamFactoryInterface $stream_factory
    ): self
    {
        return new self(
            (string) $request->getBody(),
            $request->getHeaderLine('Content-Type'),
            $uploaded_file_factory,
            $stream_factory
        );
    }
}

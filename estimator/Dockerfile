FROM python:3.12-slim
WORKDIR /srv
COPY estimator/pyproject.toml ./
COPY estimator/estimator ./estimator
RUN pip install --no-cache-dir . uvicorn
USER nobody
EXPOSE 8090
CMD ["uvicorn", "estimator.service:app", "--host", "0.0.0.0", "--port", "8090"]
